<?php

namespace MediaWiki\Extension\IPReputation\Services;

use MediaWiki\Extension\IPReputation\IPoid\IPoidDataFetcher;
use MediaWiki\Extension\IPReputation\IPoid\IPoidResponse;
use Wikimedia\IPUtils;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
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
	 * Default cache TTL in seconds (1 hour)
	 */
	private const DEFAULT_TTL = ExpirationAwareness::TTL_HOUR;
	/**
	 * Default cache TTL in seconds (5 minutes) to use for stale cache values
	 * when the backend is unavailable
	 */
	private const DEFAULT_STALE_TTL = ExpirationAwareness::TTL_MINUTE * 5;

	private int $ttl;
	private int $staleTtl;

	/**
	 * @param StatsFactory $statsFactory
	 * @param WANObjectCache $cache
	 * @param IPoidDataFetcher $ipoidDataFetcher
	 * @param int $ttl Cache TTL in seconds.
	 * @param int $staleTtl TTL for stale data retrieved from cache
	 */
	public function __construct(
		private readonly StatsFactory $statsFactory,
		private readonly WANObjectCache $cache,
		private readonly IPoidDataFetcher $ipoidDataFetcher,
		int $ttl = self::DEFAULT_TTL,
		int $staleTtl = self::DEFAULT_STALE_TTL
	) {
		$this->ttl = $ttl;
		$this->staleTtl = $staleTtl;
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
			// IPoid data is refreshed every ~12 hours. Set a one hour TTL to allow
			// IPs to get evicted from the cache relatively quickly, while ensuring reasonable freshness of data
			$this->ttl,
			function ( $oldValue, &$ttl ) use ( $ipForQuerying, $caller ) {
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
				// IPoid service unavailable (false), but we have stale data - return it with a short TTL
				// so we retry soon rather than serving stale data for the full TTL.
				// Note: null means "IP not found" (legitimate response), false means "service unavailable"
				if ( $ipoidData === false && is_array( $oldValue ) ) {
					$ttl = $this->staleTtl;
					return $oldValue;
				}
				return $ipoidData;
			},
			$callbackParams + [
				// Allow stale values to persist for up to 72 hours total (TTL_HOUR + 71 hours)
				'staleTTL' => 71 * $this->cache::TTL_HOUR,
				'lockTSE' => $this->cache::TTL_HOUR,
			]
		);

		// If no IPReputation data was found or the request failed, then return null
		if ( $data === false || $data === null ) {
			return null;
		}

		// Return the IPoid data wrapped in the value object for ease of access for the caller.
		return IPoidResponse::newFromArray( $data );
	}

}
