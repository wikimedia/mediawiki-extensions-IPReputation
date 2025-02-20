<?php

namespace MediaWiki\Extension\IPReputation\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\FormatterFactory;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Wikimedia\IPUtils;
use Wikimedia\ObjectCache\WANObjectCache;

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
	private LoggerInterface $logger;

	public function __construct(
		ServiceOptions $options,
		FormatterFactory $formatterFactory,
		HttpRequestFactory $httpRequestFactory,
		WANObjectCache $cache,
		LoggerInterface $logger
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->formatterFactory = $formatterFactory;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->cache = $cache;
		$this->logger = $logger;
	}

	/**
	 * @param UserIdentity $user
	 * @param string $ip
	 *
	 * @unstable Method signature will change in future commits.
	 * @return array|null IPoid data for the specific address, or null if there is no data
	 */
	public function getIPoidDataFor( UserIdentity $user, string $ip ): ?array {
		$fname = __METHOD__;
		$data = $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey( 'ipreputation-ipoid', $ip ),
			// IPoid data is refreshed every 24 hours and roughly 10% of its IPs drop out
			// of the database each 24-hour cycle. A one hour TTL seems reasonable to allow
			// no longer problematic IPs to get evicted from the cache relatively quickly,
			// and also means that IPs for e.g. residential proxies are updated in our cache
			// relatively quickly.
			$this->cache::TTL_HOUR,
			function () use ( $ip, $user, $fname ) {
				// If IPoid URL isn't configured, don't do any checks, let the user proceed.
				$baseUrl = $this->options->get( 'IPReputationIPoidUrl' );
				if ( !$baseUrl ) {
					$this->logger->warning(
						'Configured to check IP reputation on signup, but no IPoid URL configured'
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
				], $fname );
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
						'Got invalid JSON data while checking user {user} with IP {ip}',
						[
							'ip' => $ip,
							'user' => $user->getName(),
							'response' => $request->getContent()
						]
					);
					return null;
				}

				// IPoid will return the IP in lower case format, and we are searching for the
				// indexed value in the returned array.
				if ( !isset( $data[IPUtils::prettifyIP( $ip )] ) ) {
					// IP should always be set in the data array, but just to be safe.
					$this->logger->error(
						'Got JSON data with no IP {ip} present while checking user {user}',
						[
							'ip' => $ip,
							'user' => $user->getName(),
							'response' => $request->getContent()
						]
					);
					return null;
				}

				// We have a match and valid data structure;
				// return the values for this IP for storage in the cache.
				return $data[$ip];
			}
		);

		// Unlike null, false tells cache not to cache something. Normalize both to null before returning.
		if ( $data === false ) {
			return null;
		}

		return $data;
	}
}
