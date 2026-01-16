<?php

namespace MediaWiki\Extension\IPReputation\IPoid;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\FormatterFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

class OpenSearchIPoidDataFetcher implements IPoidDataFetcher {
	public const CONSTRUCTOR_OPTIONS = [
		'IPReputationIPoidUrl',
		'IPReputationIPoidRequestTimeoutSeconds',
		'IPReputationDeveloperMode',
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
		$url = $this->options->get( 'IPReputationIPoidUrl' ) . '/ipoid/_search';
		$timeout = $this->options->get( 'IPReputationIPoidRequestTimeoutSeconds' );
		$body = [ 'query' => [ 'bool' => [ 'filter' => [ [ 'term' => [ 'ip' => $ip ] ] ] ] ] ];
		$request = $this->httpRequestFactory->create( $url, [
			'method' => 'POST',
			'postData' => json_encode( $body ),
			'timeout' => $timeout,
			'connectTimeout' => 1,
			'sslVerifyCert' => !$this->options->get( 'IPReputationDeveloperMode' ),
			'sslVerifyHost' => !$this->options->get( 'IPReputationDeveloperMode' ),
		], $caller );
		$request->setHeader( 'Accept', 'application/json' );
		$request->setHeader( 'Content-Type', 'application/json' );
		$response = $request->execute();

		if ( !$response->isOK() ) {
			$statusFormatter = $this->formatterFactory->getStatusFormatter( RequestContext::getMain() );
			$this->logger->error( ...$statusFormatter->getPsr3MessageAndContext( $response, [
				'exception' => new RuntimeException(),
			] ) );
			return null;
		}

		$data = json_decode( $request->getContent(), true );

		if ( !$data || !isset( $data['hits'] ) ) {
			// Malformed data or error response.
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

		if ( $data['hits']['total']['value'] === 0 ) {
			return null;
		}

		$raw = $data['hits']['hits'][0]['_source'];
		// Reformat to fit the expected structure of IPoidResponse::newFromArray
		// Special handling for the `tunnels` property in OpenSearch IPoid
		$rawTunnels = $raw['tunnels'] ?? [];
		$tunnelOperators = [];
		if ( isset( $rawTunnels[0] ) && is_array( $rawTunnels[0] ) ) {
			foreach ( $rawTunnels as $tunnel ) {
				if ( isset( $tunnel['operator'] ) ) {
					$tunnelOperators[] = $tunnel['operator'];
				}
			}
		}
		return [
			'behaviors' => $raw['client']['behaviors'] ?? null,
			'risks' => $raw['risks'] ?? [ 'UNKNOWN' ],
			'tunnels' => $tunnelOperators,
			'proxies' => $raw['client']['proxies'] ?? null,
			'client_count' => $raw['client']['count'] ?? null,
			'countries' => $raw['client']['countries'] ?? null,
			'connectionTypes' => $raw['client']['types'] ?? null,
			'organization' => $raw['organization'] ?? $raw['as']['organization'] ?? null,
			'city' => $raw['location']['city'] ?? null,
			'country' => $raw['location']['country'] ?? null,
		];
	}

	/** @inheritDoc */
	public function getBackendName(): string {
		return 'opensearch';
	}
}
