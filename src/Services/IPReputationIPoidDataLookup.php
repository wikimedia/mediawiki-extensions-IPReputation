<?php
declare( strict_types=1 );

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

	/**
	 * Cache TTL in seconds (1 hour).
	 *
	 * IPoid data is refreshed every ~12 hours. One hour allows IPs to get evicted
	 * from the cache relatively quickly, while ensuring reasonable freshness of data.
	 */
	private const CACHE_TTL = WANObjectCache::TTL_HOUR;

	/**
	 * Persist stale values for up to 72 hours total (CACHE_TTL + STALE_TTL).
	 */
	private const STALE_STORE_TTL = 71 * WANObjectCache::TTL_HOUR;

	/**
	 * Cache TTL in seconds (5 minutes).
	 *
	 * Used when renewing stale data if the backend is unavailable.
	 */
	private const STALE_SERVE_TTL = 5 * WANObjectCache::TTL_MINUTE;

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
	 * @return IPoidResponse|null IPoid data for the specific address, or null if there is no data
	 */
	public function getIPoidDataForIp( string $ip, string $caller ): ?IPoidResponse {
		$ipForQuerying = IPUtils::prettifyIP( $ip );

		/** @var array|false|null $data */
		$data = $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey( 'ipreputation-ipoid', $ipForQuerying ),
			self::CACHE_TTL,
			function ( $oldValue, &$ttl ) use ( $ipForQuerying, $caller ) {
				$start = microtime( true );
				$ipoidData = $this->ipoidDataFetcher->getDataForIp( $ipForQuerying, $caller );
				$delay = microtime( true ) - $start;
				// Measure IPoid request latency.
				// If the IPoid URL is not set, this returns false and gets called frequently.
				// Avoid overloading StatsFactory with noise in that case.
				if ( $ipoidData !== false ) {
					$this->statsFactory->withComponent( 'IPReputation' )
						->getTiming( 'ipoid_data_lookup_time' )
						->setLabel( 'caller', $caller )
						->setLabel( 'backend', $this->ipoidDataFetcher->getBackendName() )
						->observeSeconds( $delay );
				}
				// IPoid service unavailable (false), but we have stale data - return it with a short TTL
				// so we retry soon rather than serving stale data for the full TTL.
				// Note: null means "IP not found" (legitimate response), false means "service unavailable"
				if ( $ipoidData === false && is_array( $oldValue ) ) {
					$ttl = self::STALE_SERVE_TTL;
					return $oldValue;
				}
				return $ipoidData;
			},
			[
				'staleTTL' => self::STALE_STORE_TTL,
				'lockTSE' => $this->cache::TTL_HOUR,
			]
		);

		if ( $data === false || $data === null ) {
			// No IPReputation data was found, or the request failed.
			return null;
		}

		return IPoidResponse::newFromArray( $data );
	}

	/**
	 * @internal For maintenance/getIPReputationData.php
	 * @codeCoverageIgnore
	 */
	public function fetchUncachedDataForIp( string $ip, string $caller ): ?IPoidResponse {
		$ipForQuerying = IPUtils::prettifyIP( $ip );
		$data = $this->ipoidDataFetcher->getDataForIp( $ipForQuerying, $caller );
		if ( $data === false || $data === null ) {
			return null;
		}
		return IPoidResponse::newFromArray( $data );
	}

}
