<?php

namespace MediaWiki\Extension\IPReputation;

use MediaWiki\Api\ApiMessage;
use MediaWiki\Auth\AbstractPreAuthenticationProvider;
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
		// If feature flag is off, don't do any checks, let the user proceed.
		if ( !$this->config->get( 'IPReputationIPoidCheckAtAccountCreation' ) ) {
			return StatusValue::newGood();
		}

		if (
			$this->permissionManager->userHasAnyRight(
				$creator,
				'ipblock-exempt',
			)
		) {
			return StatusValue::newGood();
		}

		// Override $this->logger to use the IPReputation channel (T385300).
		$this->logger = LoggerFactory::getInstance( 'IPReputation' );

		$ip = $this->manager->getRequest()->getIP();

		$data = $this->ipReputationIPoidDataLookup->getIPoidDataFor( $user, $ip );
		if ( !$data ) {
			// IPoid doesn't know anything about this IP; let the authentication request proceed.
			return StatusValue::newGood();
		}

		$shouldLogOnly = $this->config->get( 'IPReputationIPoidCheckAtAccountCreationLogOnly' );

		if ( !isset( $data['risks'] ) || !$data['risks'] ) {
			// 'risks' should always be set and populated, but if not set to 'UNKNOWN'.
			$data['risks'] = [ 'UNKNOWN' ];
		}

		if ( !isset( $data['tunnels'] ) ) {
			// 'tunnels' should always be set, but if not set to empty list.
			$data['tunnels'] = [];
		}

		$risksToBlock = $this->config->get( 'IPReputationIPoidDenyAccountCreationRiskTypes' );
		$tunnelTypesToBlock = $this->config->get( 'IPReputationIPoidDenyAccountCreationTunnelTypes' );

		$risks = $data['risks'];
		sort( $risks );

		$tunnels = $data['tunnels'];
		sort( $tunnels );

		// Allow for the possibility to exclude VPN users from having account
		// creation denied, if the only risk type known for the IP is that it's a VPN,
		// and if config is set up to allow VPN tunnel types.
		// That would be done with:
		// $wgIPReputationDenyAccountCreationRiskTypes = [ 'TUNNEL', 'CALLBACK_PROXY', ... ];
		// $wgIPReputationDenyAccountCreationTunnelTypes = [ 'PROXY', 'UNKNOWN' ];
		// If the only risk type is a TUNNEL...
		if (
			$risks === [ 'TUNNEL' ]
			// and there are tunnels listed for the IP
			&& count( $tunnels )
			// and we have configured TUNNEL as a risk type to block
			&& in_array( 'TUNNEL', $risksToBlock )
			// and the configured tunnel types to block are *not* present in the data
			&& !array_intersect( $tunnelTypesToBlock, $tunnels )
		) {
			$this->logger->debug(
				'Allowing account creation for user {user} as IP {ip} is known to IPoid '
				. 'with only non-blocked tunnels ({tunnelTypes})',
				[
					'user' => $user->getName(),
					'ip' => $ip,
					'tunnelTypes' => implode( ' ', $tunnels ),
					'IPoidData' => json_encode( $data ),
				]
			);
			return StatusValue::newGood();
		}

		// Otherwise, check for other risks.
		$blockedRisks = array_intersect( $risksToBlock, $risks );
		if ( $blockedRisks ) {
			$prefixText = $shouldLogOnly ? '[log only] Would have blocked ' : 'Blocking ';
			$this->logger->notice(
				$prefixText . 'account creation for user {user} as IP {ip} is known to IPoid '
				. 'with risks {riskTypes} (blocked due to {blockedRiskTypes})',
				[
					'user' => $user->getName(),
					'ip' => $ip,
					'riskTypes' => implode( ' ', $risks ),
					'blockedRiskTypes' => implode( ' ', $blockedRisks ),
					'IPoidData' => json_encode( $data ),
				]
			);

			$metric = $this->statsFactory->withComponent( 'IPReputation' )
				->getCounter( 'deny_account_creation' )
				->setLabel( 'wiki', WikiMap::getCurrentWikiId() )
				->setLabel( 'log_only', $shouldLogOnly ? '1' : '0' );
			foreach ( $risks as $risk ) {
				// ::setLabel only takes strings, so we cannot pass the array of risks in one call. Separating the
				// risks such that each risk is a different label should also allow better filtering over
				$metric->setLabel( 'risk_' . strtolower( $risk ), '1' );
			}
			$metric->increment();

			if ( $shouldLogOnly ) {
				return StatusValue::newGood();
			}

			return StatusValue::newFatal( ApiMessage::create( 'ipreputation-blocked-ip-reputation', 'autoblocked' ) );
		}

		return StatusValue::newGood();
	}
}
