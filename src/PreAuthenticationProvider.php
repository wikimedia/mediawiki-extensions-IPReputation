<?php

namespace MediaWiki\Extension\IPReputation;

use MediaWiki\Auth\AbstractPreAuthenticationProvider;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\WikiMap\WikiMap;
use StatusValue;
use Wikimedia\Stats\StatsFactory;

/**
 * PreAuthentication provider that checks if an IP address is known to IPoid
 *
 * @see https://wikitech.wikimedia.org/wiki/Service/IPoid
 */
class PreAuthenticationProvider extends AbstractPreAuthenticationProvider {

	private IPReputationIPoidDataLookup $ipReputationIPoidDataLookup;
	private StatsFactory $statsFactory;
	private PermissionManager $permissionManager;

	public function __construct(
		IPReputationIPoidDataLookup $ipReputationIPoidDataLookup,
		StatsFactory $statsFactory,
		PermissionManager $permissionManager
	) {
		$this->ipReputationIPoidDataLookup = $ipReputationIPoidDataLookup;
		$this->statsFactory = $statsFactory;
		$this->permissionManager = $permissionManager;
	}

	/** @inheritDoc */
	public function testForAccountCreation( $user, $creator, array $reqs ) {
		// If feature flag is off, do nothing and return early
		if ( !$this->config->get( 'IPReputationIPoidCheckAtAccountCreation' ) ) {
			return StatusValue::newGood();
		}

		// Return early if user is `ipblock-exempt` as we don't want to log these
		if (
			$this->permissionManager->userHasAnyRight(
				$creator,
				'ipblock-exempt',
			)
		) {
			return StatusValue::newGood();
		}

		// Otherwise, check if the IP is known to ipoid and log if so
		$ip = $this->manager->getRequest()->getIP();
		$ipReputationIPoidDataLookup = $this->ipReputationIPoidDataLookup;
		$statsFactory = $this->statsFactory;
		$caller = __METHOD__;
		DeferredUpdates::addCallableUpdate(
			static function () use (
				$user,
				$ip,
				$ipReputationIPoidDataLookup,
				$statsFactory,
				$caller
			) {
				$logger = LoggerFactory::getInstance( 'IPReputation' );
				$data = $ipReputationIPoidDataLookup->getIPoidDataForIp( $ip, $caller );
				if ( !$data ) {
					// IPoid doesn't know anything about this IP; return as nothing can be logged
					return;
				}

				$risks = $data->getRisks();
				sort( $risks );
				$logger->notice(
					'Account creation for user {user} is using IP {ip} that is known to IPoid with risks {riskTypes}',
					[
						'user' => $user->getName(),
						'ip' => $ip,
						'riskTypes' => implode( ' ', $risks ),
						'IPoidData' => json_encode( $data ),
					]
				);
				$metric = $statsFactory->withComponent( 'IPReputation' )
					->getCounter( 'log_account_creation' )
					->setLabel( 'wiki', WikiMap::getCurrentWikiId() );
				foreach ( $risks as $risk ) {
					// ::setLabel only takes strings, so we cannot pass the array of risks in one call. Separating
					// the risks such that each risk is a different label should also allow better filtering
					$metric->setLabel( 'risk_' . strtolower( $risk ), '1' );
				}
				$metric->increment();
			}
		);

		// This is a log-only function; status should always be good
		return StatusValue::newGood();
	}
}
