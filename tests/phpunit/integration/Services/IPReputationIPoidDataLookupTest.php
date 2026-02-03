<?php

namespace MediaWiki\Extension\IPReputation\Tests\Integration;

use MediaWiki\Extension\IPReputation\IPoid\IPoidDataFetcher;
use MediaWiki\Extension\IPReputation\IPoid\IPoidResponse;
use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\RawMessage;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use MWHttpRequest;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Stats\Metrics\TimingMetric;
use Wikimedia\Stats\StatsUtils;

/**
 * @covers \MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup
 * @covers \MediaWiki\Extension\IPReputation\IPoid\OpenSearchIPoidDataFetcher
 * @covers \MediaWiki\Extension\IPReputation\IPoid\NodeJsIPoidDataFetcher
 */
class IPReputationIPoidDataLookupTest extends MediaWikiIntegrationTestCase {
	use MockHttpTrait;

	protected function setUp(): void {
		parent::setUp();
		// Reset the $wgIPReputationIPoidUrl back to the default value, in case a local development environment
		// has a different URL.
		$this->overrideConfigValue( 'IPReputationIPoidUrl', 'http://localhost:6035' );
		$this->overrideConfigValue( 'IPReputationDataProvider', 'nodejs_ipoid' );
	}

	/**
	 * Convenience function to assert that the IPReputation IPoid timing metric was called to observe a time.
	 *
	 * @param string $caller The caller provided to TimingMetric::setLabel
	 * @return void
	 */
	private function assertTimingObserved( string $caller ): void {
		$metric = $this->getServiceContainer()
			->getStatsFactory()
			->withComponent( 'IPReputation' )
			->getTiming( 'ipoid_data_lookup_time' );

		$samples = $metric->getSamples();

		$this->assertInstanceOf( TimingMetric::class, $metric );
		$this->assertSame( 1, $metric->getSampleCount() );

		$actualLabels = array_combine( $metric->getLabelKeys(), $samples[0]->getLabelValues() );
		$this->assertArrayContains( [ 'caller' => StatsUtils::normalizeString( $caller ) ], $actualLabels );
	}

	/**
	 * Convenience function to assert that the IPReputation IPoid timing metric was not called.
	 *
	 * @return void
	 */
	private function assertTimingNotObserved(): void {
		$metric = $this->getServiceContainer()
			->getStatsFactory()
			->withComponent( 'IPReputation' )
			->getTiming( 'ipoid_data_lookup_time' );

		$this->assertInstanceOf( TimingMetric::class, $metric );
		$this->assertSame( 0, $metric->getSampleCount() );
	}

	private function getObjectUnderTest(): IPReputationIPoidDataLookup {
		return new IPReputationIPoidDataLookup(
			$this->getServiceContainer()->getStatsFactory(),
			$this->getServiceContainer()->getMainWANObjectCache(),
			$this->getServiceContainer()->get( '_IPReputationIPoidDataFetcher' )
		);
	}

	public function testGetIPoidDataForIpWhenIPoidUrlNotSet() {
		$this->overrideConfigValue( 'IPReputationIPoidUrl', null );

		// Expect no attempts to make a request to IPoid without a defined URL, and expect that a warning
		// is logged to indicate this issue.
		$this->setService( 'HttpRequestFactory', $this->createNoOpMock( HttpRequestFactory::class ) );
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'debug' )
			->with( 'IPReputation using NullDataFetcher for {caller}' );
		$this->setLogger( 'IPReputation', $mockLogger );

		$this->assertNull(
			$this->getObjectUnderTest()->getIPoidDataForIp(
				'1.2.3.4', __METHOD__
			),
			'Should return null if IPoid URL was not defined'
		);
		$this->assertTimingNotObserved();
	}

	/** @dataProvider provideIPoidBackendType */
	public function testGetIPoidDataForIpOnNonArrayResponse( string $ipoidBackend ) {
		$this->overrideConfigValue( 'IPReputationIPoidRequestTimeoutSeconds', 10 );
		$this->overrideConfigValue( 'IPReputationDataProvider', $ipoidBackend );
		$this->overrideConfigValue( 'IPReputationDeveloperMode', false );
		$this->overrideConfigValue( 'IPReputationIPoidUrl', 'http://localhost:6035' );
		$ip = '1.2.3.4';

		// Define a mock MWHttpRequest that will be returned by a mock HttpRequestFactory,
		// that simulates an invalid response from IPoid.
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$status = new StatusValue();
		$status->setOK( true );
		$mwHttpRequest->method( 'execute' )
			->willReturn( $status );
		$mwHttpRequest->method( 'getContent' )
			->willReturn( 'foo' );

		// Mock HttpRequestFactory directly so that we can check the URL and options are as expected.
		// Other tests do not check this as it should be fine to check this once.
		$mockHttpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$mockHttpRequestFactory->method( 'create' )
			->willReturnCallback( function ( $url, $options ) use ( $mwHttpRequest, $ip, $ipoidBackend ) {
				switch ( $ipoidBackend ) {
					case 'nodejs_ipoid':
						$this->assertSame( "http://localhost:6035/feed/v1/ip/$ip", $url );
						$this->assertArrayEquals(
							[ 'method' => 'GET', 'timeout' => 10, 'connectTimeout' => 1 ],
							$options
						);
						break;
					case 'opensearch_ipoid':
						$this->assertSame( "http://localhost:6035/ipoid/_search", $url );
						$postArgs = '{"query":{"bool":{"filter":[{"term":{"ip":"1.2.3.4"}}]}}}';
						$this->assertArrayEquals( [
							$postArgs,
							'method' => 'POST',
							'timeout' => 10,
							'sslVerifyCert' => true,
							'sslVerifyHost' => true,
							'connectTimeout' => 1,
						], $options );
						break;
				}
				return $mwHttpRequest;
			} );
		$this->setService( 'HttpRequestFactory', $mockHttpRequestFactory );

		// Create a mock LoggerInterface that expects a error to be logged about the invalid response from IPoid
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'error' )
			->willReturnCallback( function ( $msg, $context ) use ( $ip, $mwHttpRequest ) {
				$this->assertSame( 'Got unexpected data from IPoid while checking IP {ip} for {caller}', $msg );
				// Check that the caller is specified, but ignore its value as it may change
				$this->assertArrayHasKey( 'caller', $context );
				unset( $context['caller'] );
				$this->assertArrayEquals(
					[ 'ip' => $ip, 'response' => $mwHttpRequest->getContent() ], $context, false, true
				);
			} );
		$this->setLogger( 'IPReputation', $mockLogger );

		$this->assertNull(
			$this->getObjectUnderTest( $mockLogger )->getIPoidDataForIp( $ip, __METHOD__ ),
			'Should return null when IPoid returns malformed data (service error)'
		);
		// Timing is not observed when fetcher returns false (service unavailable/malformed data)
		$this->assertTimingNotObserved();
	}

	public function provideIPoidBackendType(): array {
		return [
			'OpenSearch IPoid' => [ 'opensearch_ipoid' ],
			'NodeJS IPoid' => [ 'nodejs_ipoid' ],
		];
	}

	public function testGetIPoidDataForIpOnArrayResponseNotContainingIP() {
		// Make a mock MWHttpRequest that will simulate a response which is invalid from IPoid
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$status = new StatusValue();
		$status->setOK( true );
		$mwHttpRequest->method( 'execute' )
			->willReturn( $status );
		$mwHttpRequest->method( 'getContent' )
			->willReturn( json_encode( [ 'foo' => 'bar' ] ) );
		$this->installMockHttp( $mwHttpRequest );

		// Create a mock LoggerInterface that expects a error to be logged about the invalid response from IPoid
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'error' )
			->willReturnCallback( function ( $msg, $context ) use ( $mwHttpRequest ) {
				$this->assertSame(
					'Got JSON data from IPoid missing the requested IP while checking {ip} for {caller}', $msg
				);
				// Check that the caller is specified, but ignore it's value as it may change
				$this->assertArrayHasKey( 'caller', $context );
				unset( $context['caller'] );
				$this->assertArrayEquals(
					[ 'ip' => '1.2.3.4', 'response' => $mwHttpRequest->getContent() ], $context, false, true
				);
			} );

		$this->setLogger( 'IPReputation', $mockLogger );
		$this->assertNull(
			$this->getObjectUnderTest()->getIPoidDataForIp( '1.2.3.4', __METHOD__ ),
			'Should return null when IPoid returns malformed response (service error)'
		);
		// Timing is not observed when fetcher returns false (service unavailable/malformed data)
		$this->assertTimingNotObserved();
	}

	public function testGetIPoidDataForIpWhenIPoidReturnsWith500Error() {
		// Mock the IPoid returns a response with a HTTP status code of 500,
		// indicating some kind of server error.
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$status = StatusValue::newFatal( new RawMessage( 'Message for 500 error' ) );
		$mwHttpRequest->method( 'execute' )
			->willReturn( $status );
		$mwHttpRequest->method( 'getStatus' )
			->willReturn( 500 );
		$this->installMockHttp( $mwHttpRequest );

		// Create a mock LoggerInterface that expects a error to be logged about the 500 error from IPoid
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'error' );

		$this->setLogger( 'IPReputation', $mockLogger );
		$this->assertNull(
			$this->getObjectUnderTest()->getIPoidDataForIp(
				'1.2.3.4', __METHOD__
			),
			'Should return null when IPoid returns 500 error (no cached data available)'
		);
		// Timing is not observed when fetcher returns false (service unavailable)
		$this->assertTimingNotObserved();
	}

	public function testGetIPoidDataForIpWhenIPoidHasNoMatch() {
		// Mock the IPoid returns a response with a HTTP status code of 404,
		// indicating that the IP was not found in the dataset.
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$status = new StatusValue();
		$status->setOK( false );
		$mwHttpRequest->method( 'execute' )
			->willReturn( $status );
		$mwHttpRequest->method( 'getStatus' )
			->willReturn( 404 );
		$this->installMockHttp( $mwHttpRequest );

		// Expect no logs if the IP is not known to IPoid
		$mockLogger = $this->createNoOpMock( LoggerInterface::class );

		$this->assertNull(
			$this->getObjectUnderTest( $mockLogger )->getIPoidDataForIp(
				'1.2.3.4', __METHOD__
			),
			'Should return null if IP was not present in response from IPoid'
		);
		$this->assertTimingObserved( __METHOD__ );
	}

	public function testGetIPoidDataForIpOnArrayResponse() {
		// Mock IPoid returning a valid response with IPReputation data
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$status = new StatusValue();
		$status->setOK( true );
		$mwHttpRequest->method( 'execute' )
			->willReturn( $status );
		$mwHttpRequest->method( 'getContent' )
			->willReturn( json_encode( [ '1.2.3.4' => [
				'risks' => [ 'TUNNEL' ],
				'tunnels' => [ 'PROXY' ]
			] ] ) );
		$this->installMockHttp( $mwHttpRequest );

		$this->assertArrayEquals(
			IPoidResponse::newFromArray( [
				'risks' => [ 'TUNNEL' ],
				'tunnels' => [ 'PROXY' ]
			] )->jsonSerialize(),
			$this->getObjectUnderTest()->getIPoidDataForIp(
				'1.2.3.4', __METHOD__
			)->jsonSerialize(),
			'Should return array from IPoid if response is valid and the IP is known to IPoid'
		);
		$this->assertTimingObserved( __METHOD__ );
	}

	public function testGetIPoidDataForIpWhenOpenSearchIPoidHasNoMatch() {
		$this->overrideConfigValue( 'IPReputationDataProvider', 'opensearch_ipoid' );
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$status = new StatusValue();
		$status->setOK( true );
		$mwHttpRequest->method( 'execute' )
			->willReturn( $status );
		$mwHttpRequest->method( 'getStatus' )
			->willReturn( 200 );
		$mwHttpRequest->method( 'getContent' )
			->willReturn( json_encode( [ 'hits' => [
				'total' => [
					'value' => 0
				],
			] ] ) );
		$this->installMockHttp( $mwHttpRequest );

		// Expect no logs if the IP is not known to IPoid
		$mockLogger = $this->createNoOpMock( LoggerInterface::class );

		$this->assertNull(
			$this->getObjectUnderTest( $mockLogger )->getIPoidDataForIp(
				'1.2.3.4', __METHOD__
			),
			'Should return null if IP was not present in response from IPoid'
		);
		$this->assertTimingObserved( __METHOD__ );
	}

	public function testGetOpenSearchIPoidDataForIpOnArrayResponse() {
		$this->overrideConfigValue( 'IPReputationDataProvider', 'opensearch_ipoid' );
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$status = new StatusValue();
		$status->setOK( true );
		$mwHttpRequest->method( 'execute' )
			->willReturn( $status );
		$mwHttpRequest->method( 'getContent' )
			->willReturn( json_encode( [ 'hits' => [
				'total' => [
					'value' => 1
				],
				'hits' => [
					[
						'_source' => [
							'risks' => [ 'TUNNEL' ],
							'organization' => 'ACME',
							'client' => [
								'count' => 5,
								'types' => [ 'VPN' ],
								'behaviors' => [ 'TOR' ]
							],
							'location' => [
								'city' => 'Dublin',
								'country' => 'IE'
							],
							'tunnels' => [
								[
									'operator' => 'TEST_PROXY',
									'type' => 'PROXY',
									'anonymous' => true,
								],
								[
									'operator' => 'TEST_VPN',
									'type' => 'VPN',
									'anonymous' => true,
								],
								[
									'type' => 'FOO',
									'anonymous' => false,
								]
							]
						]
					]
				] ]
			] ) );
		$this->installMockHttp( $mwHttpRequest );

		$expected = IPoidResponse::newFromArray( [
			'risks' => [ 'TUNNEL' ],
			'tunnels' => [ 'TEST_PROXY', 'TEST_VPN' ],
			'client_count' => 5,
			'organization' => 'ACME',
			'city' => 'Dublin',
			'country' => 'IE',
			'behaviors' => [ 'TOR' ],
			'connectionTypes' => [ 'VPN' ],
		] );
		$actual = $this->getObjectUnderTest()->getIPoidDataForIp(
			'1.2.3.4', __METHOD__
		);
		$this->assertArrayEquals(
			$expected->jsonSerialize(),
			$actual->jsonSerialize(),
			'Should return array from IPoid if response is valid and the IP is known to IPoid'
		);
		$this->assertTimingObserved( __METHOD__ );
	}

	public function testGetIPoidDataForIpBypassesCache() {
		$ip = '1.2.3.4';
		$localCache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$localCache->set( $localCache->makeGlobalKey( 'ipreputation-ipoid', $ip ), [
			'risks' => [ 'STALE' ]
		] );
		$mockFetcher = $this->createMock( IPoidDataFetcher::class );
		$mockFetcher->expects( $this->once() )
			->method( 'getDataForIp' )
			->willReturn( [
				'risks' => [ 'FRESH' ],
			] );
		$mockFetcher->method( 'getBackendName' )->willReturn( 'test' );
		$lookup = new IPReputationIPoidDataLookup(
			$this->getServiceContainer()->getStatsFactory(),
			$localCache,
			$mockFetcher
		);

		$result = $lookup->getIPoidDataForIp( $ip, __METHOD__ );
		$this->assertSame(
			[ 'STALE' ],
			$result->getRisks(),
			'Should use the STALE cached value and not call the fetcher'
		);
		$result = $lookup->getIPoidDataForIp( $ip, __METHOD__, false );
		$this->assertSame(
			[ 'FRESH' ],
			$result->getRisks(),
			'Should ignore the STALE cached value and call the fetcher'
		);
	}

	public function testStaleValueReturnedWhenIPoidUnavailable() {
		$ip = '1.2.3.4';
		$localCache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );

		$mockFetcher = $this->createMock( IPoidDataFetcher::class );
		$mockFetcher->method( 'getBackendName' )->willReturn( 'test' );
		// First call returns valid data, second call simulates IPoid being down, third call simulates fresh data
		$mockFetcher->expects( $this->exactly( 3 ) )
			->method( 'getDataForIp' )
			->willReturnOnConsecutiveCalls(
				[ 'risks' => [ 'TUNNEL' ] ],
				false,
				[ 'risks' => [ 'VPN' ] ]
			);

		// Use a 1-second TTL so we can test stale value retrieval
		$lookup = new IPReputationIPoidDataLookup(
			$this->getServiceContainer()->getStatsFactory(),
			$localCache,
			$mockFetcher,
			1,
			1
		);

		// Initial call should populate the cache
		$result = $lookup->getIPoidDataForIp( $ip, __METHOD__ );
		$this->assertSame(
			[ 'TUNNEL' ],
			$result->getRisks(),
			'Initial call should return data from IPoid'
		);

		// Wait for TTL to expire (1 second TTL + small buffer)
		usleep( 1100000 );

		// Second call should return stale data since IPoid is unavailable
		$result = $lookup->getIPoidDataForIp( $ip, __METHOD__ );
		$this->assertSame(
			[ 'TUNNEL' ],
			$result->getRisks(),
			'Should return stale cached data when IPoid is unavailable'
		);

		// Wait for stale TTL to expire (1 second TTL + small buffer)
		usleep( 1100000 );

		// Third call should return fresh data since IPoid is available again
		$result = $lookup->getIPoidDataForIp( $ip, __METHOD__ );
		$this->assertSame(
			[ 'VPN' ],
			$result->getRisks(),
			'Should return fresh data when IPoid is available again'
		);
	}

	public function testStaleValueNotReturnedWhenIPNotFound() {
		$ip = '1.2.3.4';
		$localCache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );

		$mockFetcher = $this->createMock( IPoidDataFetcher::class );
		$mockFetcher->method( 'getBackendName' )->willReturn( 'test' );
		// First call returns valid data, second call returns null (IP not found in IPoid database)
		$mockFetcher->expects( $this->exactly( 2 ) )
			->method( 'getDataForIp' )
			->willReturnOnConsecutiveCalls(
				[ 'risks' => [ 'TUNNEL' ] ],
				null
			);

		// Use a 1-second TTL so we can test cache expiration
		$lookup = new IPReputationIPoidDataLookup(
			$this->getServiceContainer()->getStatsFactory(),
			$localCache,
			$mockFetcher,
			1,
			1
		);

		// Initial call should populate the cache
		$result = $lookup->getIPoidDataForIp( $ip, __METHOD__ );
		$this->assertSame(
			[ 'TUNNEL' ],
			$result->getRisks(),
			'Initial call should return data from IPoid'
		);

		// Wait for TTL to expire (1 second TTL + small buffer)
		usleep( 1100000 );

		// Second call returns null (IP not found), should NOT return stale data
		// because null means "IP legitimately not found", not "service unavailable"
		$result = $lookup->getIPoidDataForIp( $ip, __METHOD__ );
		$this->assertNull(
			$result,
			'Should return null when IP is not found (not stale data)'
		);
	}

}
