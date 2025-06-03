<?php

namespace MediaWiki\Extension\IPReputation\Hooks\Handlers;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterBuilderHook;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterCustomProtectedVariablesHook;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterGenerateAccountCreationVarsHook;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterGenerateUserVarsHook;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterInterceptVariableHook;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserNameUtils;
use Wikimedia\IPUtils;

// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
// For onAbuseFilter_builder and onAbuseFilter_generateUserVars

/**
 * Registers and provides values for IPReputation AbuseFilter variables.
 */
class AbuseFilterHandler implements
	AbuseFilterGenerateAccountCreationVarsHook,
	AbuseFilterGenerateUserVarsHook,
	AbuseFilterBuilderHook,
	AbuseFilterCustomProtectedVariablesHook,
	AbuseFilterInterceptVariableHook
{

	/** @internal Access outside this class is intended for PHPUnit tests only */
	public const SUPPORTED_VARIABLES = [
		'ip_reputation_tunnel_operators',
		'ip_reputation_risk_types',
		'ip_reputation_client_proxies',
		'ip_reputation_client_behaviors',
		'ip_reputation_client_count',
		'ip_reputation_ipoid_known',
	];

	private UserNameUtils $userNameUtils;
	private IPReputationIPoidDataLookup $ipoidDataLookup;

	public function __construct( UserNameUtils $userNameUtils, IPReputationIPoidDataLookup $ipoidDataLookup ) {
		$this->userNameUtils = $userNameUtils;
		$this->ipoidDataLookup = $ipoidDataLookup;
	}

	/**
	 * Defines the AbuseFilter IP reputation variables so that a filter can use them.
	 *
	 * @inheritDoc
	 */
	public function onAbuseFilter_generateUserVars( VariableHolder $vars, User $user, ?RecentChange $rc ) {
		foreach ( self::SUPPORTED_VARIABLES as $variable ) {
			// Only add the variables if they are not set. They may have been set by
			// ::onAbuseFilterGenerateAccountCreationVars below and those definitions should take priority.
			if ( !$vars->varIsSet( $variable ) ) {
				$vars->setLazyLoadVar(
					$variable,
					$this->getMethodForVariable( $variable ),
					[ 'userIdentity' => $user, 'rc' => $rc, 'action' => 'other' ]
				);
			}
		}
	}

	/**
	 * Defines the AbuseFilter IP reputation variables for account creation too.
	 *
	 * This is so that we can prevent or flag account creations made from IP addresses which match
	 * specific IPReputation data. Because the IP address is not disclosed this does not cause
	 * a privacy problem.
	 *
	 * @inheritDoc
	 */
	public function onAbuseFilterGenerateAccountCreationVars(
		VariableHolder $vars, UserIdentity $creator, UserIdentity $createdUser, bool $autocreate, ?RecentChange $rc
	) {
		foreach ( self::SUPPORTED_VARIABLES as $variable ) {
			$vars->setLazyLoadVar(
				$variable,
				$this->getMethodForVariable( $variable ),
				[
					'userIdentity' => $creator, 'rc' => $rc, 'action' => 'accountcreation',
					'createdUser' => $createdUser, 'autocreate' => $autocreate,
				]
			);
		}
	}

	/**
	 * Actually generates the data for IPReputation AbuseFilter variables.
	 *
	 * This is done lazily to avoid HTTP requests to get IPReputation data when
	 * AbuseFilter does not need this data for any matching filter.
	 *
	 * The variable value will be null if the user type does not support IPReputation
	 * variables or the IP being used is not known to IPoid.
	 *
	 * @inheritDoc
	 */
	public function onAbuseFilter_interceptVariable(
		string $method, VariableHolder $vars, array $parameters, &$result
	) {
		if ( !in_array( $this->getVariableForMethod( $method ), self::SUPPORTED_VARIABLES ) ) {
			return true;
		}

		if ( !$this->shouldGenerateVariableValue( $parameters ) ) {
			$result = null;
			return false;
		}

		$ip = $this->getTargetIP( $parameters );
		if ( !$ip ) {
			$result = null;
			return false;
		}

		$data = $this->ipoidDataLookup->getIPoidDataForIp( $ip, __METHOD__ );

		// If no IPoid data exists, then make the result null to indicate no match.
		// The exception is ip-reputation-ipoid-known which is false when no IPoid data exists.
		if ( $data === null && $method !== 'ip-reputation-ipoid-known' ) {
			$result = null;
			return false;
		}

		switch ( $method ) {
			case 'ip-reputation-ipoid-known':
				$result = (bool)$data;
				break;
			case 'ip-reputation-tunnel-operators':
				$result = $data->getTunnelOperators();
				break;
			case 'ip-reputation-risk-types':
				$result = $data->getRisks();
				break;
			case 'ip-reputation-client-proxies':
				$result = $data->getProxies();
				break;
			case 'ip-reputation-client-behaviors':
				$result = $data->getBehaviors();
				break;
			case 'ip-reputation-client-count':
				$result = $data->getNumUsersOnThisIP();
				break;
		}

		return false;
	}

	/**
	 * Registers the IPReputation AbuseFilter variables as valid variables so that they
	 * can be selected in a dropdown menu.
	 *
	 * @inheritDoc
	 */
	public function onAbuseFilter_builder( array &$realValues ) {
		foreach ( self::SUPPORTED_VARIABLES as $variable ) {
			// Generates:
			// * abusefilter-edit-builder-vars-ip-reputation-tunnel-operators
			// * abusefilter-edit-builder-vars-ip-reputation-risk-types
			// * abusefilter-edit-builder-vars-ip-reputation-client-proxies
			// * abusefilter-edit-builder-vars-ip-reputation-client-behaviors
			// * abusefilter-edit-builder-vars-ip-reputation-client-count
			// * abusefilter-edit-builder-vars-ip-reputation-ipoid-known
			$realValues['vars'][$variable] = $this->getMethodForVariable( $variable );
		}
	}

	/**
	 * All IPReputation AbuseFilter variables should be protected as they contain non-public data.
	 *
	 * @inheritDoc
	 */
	public function onAbuseFilterCustomProtectedVariables( array &$variables ) {
		$variables = array_merge( $variables, self::SUPPORTED_VARIABLES );
	}

	/**
	 * Generates the method name used to compute the value of the variable for an associated IPReputation AbuseFilter
	 * variable.
	 *
	 * @param string $variable
	 * @return string
	 */
	private function getMethodForVariable( string $variable ): string {
		return str_replace( '_', '-', $variable );
	}

	/**
	 * Generates the variable name for an associated lazy loaded AbuseFilter method name.
	 *
	 * @param string $variable
	 * @return string
	 */
	private function getVariableForMethod( string $variable ): string {
		return str_replace( '-', '_', $variable );
	}

	/**
	 * Gets the IP address to be used as the target IP for the IPoid request.
	 *
	 * @param array $parameters The parameters provided to {@link self::onAbuseFilter_interceptVariable}
	 * @return string|null The target IP address for IPoid data queries. May be an empty string or null
	 *   if no IP could be found.
	 */
	private function getTargetIP( array $parameters ): ?string {
		/** @var RecentChange|null $rc */
		$rc = $parameters['rc'];
		if ( $rc !== null ) {
			return $rc->getAttribute( 'rc_ip' );
		} else {
			return RequestContext::getMain()->getRequest()->getIP();
		}
	}

	/**
	 * Allow IPReputation data only for:
	 * * Users performing actions while logged out
	 * * Users performing actions using a temporary account
	 * * Creation of an account while logged out
	 * In all other cases, IPReputation data should not be generated.
	 *
	 * @param array $parameters The parameters provided to {@link self::onAbuseFilter_interceptVariable}
	 * @return bool
	 */
	private function shouldGenerateVariableValue( array $parameters ): bool {
		/** @var UserIdentity $userIdentity */
		$userIdentity = $parameters['userIdentity'];

		// Always exclude autocreations of named accounts.
		if ( $parameters['action'] === 'accountcreation' && $parameters['autocreate'] ) {
			/** @var UserIdentity $createdUser */
			$createdUser = $parameters['createdUser'];
			if ( !$this->userNameUtils->isTemp( $createdUser->getName() ) ) {
				return false;
			}
		}

		// Allow generation for actions performed by temporary accounts and IPs.
		if (
			IPUtils::isValid( $userIdentity->getName() ) ||
			$this->userNameUtils->isTemp( $userIdentity->getName() )
		) {
			return true;
		}

		// Only allow generation of variables for named accounts if the action is an account creation (excluding
		// autocreations).
		if ( $parameters['action'] !== 'accountcreation' || $parameters['autocreate'] ) {
			return false;
		}

		// We handled the case of account creations while logged out when the $userIdentity being an IP above.
		// The account creation can also be performed while logged out if the performer is the same as the creator.
		/** @var UserIdentity $createdUser */
		$createdUser = $parameters['createdUser'];
		return $createdUser->equals( $userIdentity );
	}
}
