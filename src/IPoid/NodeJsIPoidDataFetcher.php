<?php

namespace MediaWiki\Extension\IPReputation\IPoid;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\FormatterFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

class NodeJsIPoidDataFetcher implements IPoidDataFetcher {
	public const CONSTRUCTOR_OPTIONS = [
		'IPReputationIPoidUrl',
		'IPReputationIPoidRequestTimeoutSeconds',
	];

	public function __construct(
		private readonly ServiceOptions $options,
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly FormatterFactory $formatterFactory,
		private readonly LoggerInterface $logger
	) {
		$this->options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/** @inheritDoc */
	public function getDataForIp( string $ip, string $caller ): array|false|null {
		$baseUrl = $this->options->get( 'IPReputationIPoidUrl' );
		$timeout = $this->options->get( 'IPReputationIPoidRequestTimeoutSeconds' );
		$url = $baseUrl . '/feed/v1/ip/' . $ip;
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
				'Got unexpected data from IPoid while checking IP {ip} for {caller}',
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
		if ( !isset( $data[$ip] ) ) {
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
		$raw = $data[$ip];
		return $raw;
	}

	/** @inheritDoc */
	public function getBackendName(): string {
		return 'nodejs';
	}
}
