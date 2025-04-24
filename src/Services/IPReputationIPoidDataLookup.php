<?php

namespace MediaWiki\Extension\IPReputation\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\IPReputation\IPoidResponse;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\FormatterFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;
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

	/** @internal Only public for service wiring use. */
	public const CONSTRUCTOR_OPTIONS = [
		'IPReputationIPoidUrl',
		'IPReputationIPoidRequestTimeoutSeconds',
	];

	private ServiceOptions $options;
	private HttpRequestFactory $httpRequestFactory;
	private WANObjectCache $cache;
	private FormatterFactory $formatterFactory;
	private StatsFactory $statsFactory;
	private LoggerInterface $logger;

	public function __construct(
		ServiceOptions $options,
		FormatterFactory $formatterFactory,
		HttpRequestFactory $httpRequestFactory,
		StatsFactory $statsFactory,
		WANObjectCache $cache,
		LoggerInterface $logger
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->formatterFactory = $formatterFactory;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->statsFactory = $statsFactory;
		$this->cache = $cache;
		$this->logger = $logger;
	}

	/**
	 * Fetches IPReputation data from IPoid about a given IP address.
	 *
	 * @param string $ip The IP address to lookup IPReputation data on
	 * @param string $caller The method performing this lookup, for profiling and errors
	 * @return IPoidResponse|null IPoid data for the specific address, or null if there is no data
	 */
	public function getIPoidDataForIp( string $ip, string $caller ): ?IPoidResponse {
		/** @var array|false|null $data */
		$data = $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey( 'ipreputation-ipoid', $ip ),
			// IPoid data is refreshed every 24 hours and roughly 10% of its IPs drop out
			// of the database each 24-hour cycle. A one hour TTL seems reasonable to allow
			// no longer problematic IPs to get evicted from the cache relatively quickly,
			// and also means that IPs for e.g. residential proxies are updated in our cache
			// relatively quickly.
			$this->cache::TTL_HOUR,
			function () use ( $ip, $caller ) {
				$start = microtime( true );
				$ipoidData = $this->getIPoidDataForIPInternal( $ip, $caller );
				$delay = microtime( true ) - $start;

				// Track the time it took to make a request to IPoid. We do not do this if we could not cache the
				// response to avoid overloading the StatsFactory backend as this method gets called frequently.
				// At the moment the cache is only missed if the IPoid URL is not set.
				if ( $ipoidData !== false ) {
					$this->statsFactory->withComponent( 'IPReputation' )
						->getTiming( 'ipoid_data_lookup_time' )
						->setLabel( 'caller', $caller )
						->observeSeconds( $delay );
				}

				return $ipoidData;
			}
		);

		// If no IPReputation data was found or the request failed, then return null
		if ( $data === false || $data === null ) {
			return null;
		}

		// Return the IPoid data wrapped in the value object for ease of access for the caller.
		return IPoidResponse::newFromArray( $data );
	}

	/**
	 * Helper for {@link self::getIPoidDataForIp} that does not cache the response from IPoid, and returns
	 * a value that can be returned from the callback provided to {@link WANObjectCache::getWithSetCallback}.
	 *
	 * @param string $ip See {@link self::getIPoidDataForIp}
	 * @param string $caller See {@link self::getIPoidDataForIp}
	 * @return array|false|null The value to be returned and also cached. If false then the value should not be
	 *   cached and interpreted as null for other code.
	 */
	private function getIPoidDataForIPInternal( string $ip, string $caller ) {
		// If IPoid URL isn't configured, then raise a warning and return that no data was found.
		$baseUrl = $this->options->get( 'IPReputationIPoidUrl' );
		if ( !$baseUrl ) {
			$this->logger->warning(
				'IPReputation attempted to query IPoid but the IPoid URL is not ' .
				'configured when checking IP for {caller}',
				[ 'caller' => $caller ]
			);
			// Don't cache this.
			return false;
		}

		$timeout = $this->options->get( 'IPReputationIPoidRequestTimeoutSeconds' );
		// Convert IPv6 to lowercase, to match IPoid storage format.
		$url = $baseUrl . '/feed/v1/ip/' . IPUtils::prettifyIP( $ip );
		$request = $this->httpRequestFactory->create( $url, [
			'method' => 'GET',
			'timeout' => $timeout,
			'connectTimeout' => 1,
		], $caller );
		$response = $request->execute();

		if ( !$response->isOK() ) {
			// Probably a 404, which means IPoid doesn't know about the IP.
			// If not a 404, log it, so we can figure out what happened.
			if ( $request->getStatus() !== 404 ) {
				$statusFormatter = $this->formatterFactory->getStatusFormatter( RequestContext::getMain() );
				$this->logger->error( ...$statusFormatter->getPsr3MessageAndContext( $response, [
					'exception' => new RuntimeException(),
				] ) );
			}

			return null;
		}

		$data = json_decode( $request->getContent(), true );

		if ( !$data ) {
			// Malformed data.
			$this->logger->error(
				'Got invalid JSON data from IPoid while checking IP {ip} for {caller}',
				[
					'ip' => $ip,
					'response' => $request->getContent(),
					'caller' => $caller,
				]
			);
			return null;
		}

		// IPoid will return the IP in lower case format, and we are searching for the
		// indexed value in the returned array.
		if ( !isset( $data[IPUtils::prettifyIP( $ip )] ) ) {
			// IP should always be set in the data array, but just to be safe.
			$this->logger->error(
				'Got JSON data from IPoid missing the requested IP while checking {ip} for {caller}',
				[
					'ip' => $ip,
					'response' => $request->getContent(),
					'caller' => $caller,
				]
			);
			return null;
		}

		// We have a match and valid data structure;
		// return the values for this IP for storage in the cache.
		return $data[$ip];
	}
}
