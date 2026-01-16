<?php

namespace MediaWiki\Extension\IPReputation\Hooks\Handlers;

use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWiki\Hook\GetSecurityLogContextHook;

class GetSecurityLogContextHandler implements GetSecurityLogContextHook {

	private bool $enableGetSecurityLogContext = false;

	public function __construct( private readonly IPReputationIPoidDataLookup $ipReputationIPoidDataLookup ) {
	}

	/**
	 * Used only for testing, to avoid the GetSecurityLogContext hook from running for other
	 * extensions
	 * @internal
	 */
	public function enableHookHandlerForTest(): void {
		$this->enableGetSecurityLogContext = true;
	}

	/** @inheritDoc */
	public function onGetSecurityLogContext( array $info, array &$context ): void {
		// Avoid running for other extension that invoke this hook during tests
		if ( defined( 'MW_PHPUNIT_TEST' ) && !$this->enableGetSecurityLogContext ) {
			return;
		}
		$ipoidResponse = $this->ipReputationIPoidDataLookup->getIPoidDataForIp(
			$info['request']->getIP(), __METHOD__
		);
		if ( !$ipoidResponse ) {
			return;
		}
		$tunnels = $ipoidResponse->getTunnelOperators();
		if ( $tunnels ) {
			$context['ip_reputation_tunnels'] = $tunnels;
		}
		$risks = $ipoidResponse->getRisks();
		if ( $risks ) {
			$context['ip_reputation_risks'] = $risks;
		}
		$proxies = $ipoidResponse->getProxies();
		if ( $proxies ) {
			$context['ip_reputation_proxies'] = $proxies;
		}
		$behaviors = $ipoidResponse->getBehaviors();
		if ( $behaviors ) {
			$context['ip_reputation_behaviors'] = $behaviors;
		}
	}
}
