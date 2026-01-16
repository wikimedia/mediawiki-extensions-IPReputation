<?php

namespace MediaWiki\Extension\IPReputation\Services;

use MediaWiki\Extension\IPReputation\IPoid\IPoidDataFetcher;
use MediaWiki\Extension\IPReputation\IPoid\IPoidResponse;
use Wikimedia\IPUtils;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Stats\StatsFactory;

/**
 * Service used to check if an IP address is known to IPoid and if so provide IPReputation data about that
 * IP address.
 *
 * @see https://wikitech.wikimedia.org/wiki/Service/IPoid
 */
class IPReputationIPoidDataLookup {

	public function __construct(
		private readonly StatsFactory $statsFactory,
		private readonly WANObjectCache $cache,
		private readonly IPoidDataFetcher $ipoidDataFetcher
	) {
	}

	/**
	 * Fetches IPReputation data from IPoid about a given IP address.
	 *
	 * @param string $ip The IP address to lookup IPReputation data on
	 * @param string $caller The method performing this lookup, for profiling and errors
	 * @param bool $useCache If the request should use the cache
	 * @return IPoidResponse|null IPoid data for the specific address, or null if there is no data
	 */
	public function getIPoidDataForIp( string $ip, string $caller, bool $useCache = true ): ?IPoidResponse {
		$ipForQuerying = IPUtils::prettifyIP( $ip );
		$callbackParams = [];
		if ( !$useCache ) {
			$callbackParams['minAsOf'] = INF;
		}
		/** @var array|false|null $data */
		$data = $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey( 'ipreputation-ipoid', $ipForQuerying ),
			// IPoid data is refreshed every 24 hours and roughly 10% of its IPs drop out
			// of the database each 24-hour cycle. A one hour TTL seems reasonable to allow
			// no longer problematic IPs to get evicted from the cache relatively quickly,
			// and also means that IPs for e.g. residential proxies are updated in our cache
			// relatively quickly.
			$this->cache::TTL_HOUR,
			function () use ( $ipForQuerying, $caller ) {
				$start = microtime( true );
				$ipoidData = $this->ipoidDataFetcher->getDataForIp( $ipForQuerying, $caller );
				$delay = microtime( true ) - $start;
				// Track the time it took to make a request to IPoid. We do not do this if we could not cache the
				// response to avoid overloading the StatsFactory backend as this method gets called frequently.
				// At the moment the cache is only missed if the IPoid URL is not set.
				if ( $ipoidData !== false ) {
					$this->statsFactory->withComponent( 'IPReputation' )
						->getTiming( 'ipoid_data_lookup_time' )
						->setLabel( 'caller', $caller )
						->setLabel( 'backend', $this->ipoidDataFetcher->getBackendName() )
						->observeSeconds( $delay );
				}
				return $ipoidData;
			},
			$callbackParams
		);

		// If no IPReputation data was found or the request failed, then return null
		if ( $data === false || $data === null ) {
			return null;
		}

		// Return the IPoid data wrapped in the value object for ease of access for the caller.
		return IPoidResponse::newFromArray( $data );
	}

}
