<?php

namespace MediaWiki\Extension\IPReputation\Tests\Integration;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\IPReputation\IPoidResponse;
use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\RawMessage;
use MediaWiki\Logger\LoggerFactory;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use MWHttpRequest;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\Stats\Metrics\TimingMetric;
use Wikimedia\Stats\StatsUtils;

/**
 * @covers \MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup
 */
class IPReputationIPoidDataLookupTest extends MediaWikiIntegrationTestCase {
	use MockHttpTrait;

	protected function setUp(): void {
		parent::setUp();
		// Reset the $wgIPReputationIPoidUrl back to the default value, in case a local development environment
		// has a different URL.
		$this->overrideConfigValue( 'IPReputationIPoidUrl', 'http://localhost:6035' );
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
		$this->assertSame( [ 'caller' => StatsUtils::normalizeString( $caller ) ], $actualLabels );
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

	private function getObjectUnderTest( $mockLogger = null ): IPReputationIPoidDataLookup {
		return new IPReputationIPoidDataLookup(
			new ServiceOptions(
				IPReputationIPoidDataLookup::CONSTRUCTOR_OPTIONS,
				$this->getServiceContainer()->getMainConfig()
			),
			$this->getServiceContainer()->getFormatterFactory(),
			$this->getServiceContainer()->getHttpRequestFactory(),
			$this->getServiceContainer()->getStatsFactory(),
			$this->getServiceContainer()->getMainWANObjectCache(),
			$mockLogger ?? LoggerFactory::getInstance( 'IPReputation' )
		);
	}

	public function testGetIPoidDataForIpWhenIPoidUrlNotSet() {
		$this->overrideConfigValue( 'IPReputationIPoidUrl', null );

		// Expect no attempts to make a request to IPoid without a defined URL, and expect that a warning
		// is logged to indicate this issue.
		$this->setService( 'HttpRequestFactory', $this->createNoOpMock( HttpRequestFactory::class ) );
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'warning' )
			->with(
				'IPReputation attempted to query IPoid but the IPoid URL is not ' .
					'configured when checking IP for {caller}'
			);

		$this->assertNull(
			$this->getObjectUnderTest( $mockLogger )->getIPoidDataForIp(
				'1.2.3.4', __METHOD__
			),
			'Should return null if IPoid URL was not defined'
		);
		$this->assertTimingNotObserved();
	}

	public function testGetIPoidDataForIpOnNonArrayResponse() {
		$this->overrideConfigValue( 'IPReputationIPoidRequestTimeoutSeconds', 10 );
		$this->overrideConfigValue( 'IPReputationDeveloperMode', false );
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
			->willReturnCallback( function ( $url, $options ) use ( $mwHttpRequest, $ip ) {
				$this->assertSame( "http://localhost:6035/feed/v1/ip/$ip", $url );
				$this->assertArrayEquals( [ 'method' => 'GET', 'timeout' => 10, 'connectTimeout' => 1 ], $options );
				return $mwHttpRequest;
			} );
		$this->setService( 'HttpRequestFactory', $mockHttpRequestFactory );

		// Create a mock LoggerInterface that expects a error to be logged about the invalid response from IPoid
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'error' )
			->willReturnCallback( function ( $msg, $context ) use ( $ip, $mwHttpRequest ) {
				$this->assertSame( 'Got invalid JSON data from IPoid while checking IP {ip} for {caller}', $msg );
				// Check that the caller is specified, but ignore it's value as it may change
				$this->assertArrayHasKey( 'caller', $context );
				unset( $context['caller'] );
				$this->assertArrayEquals(
					[ 'ip' => $ip, 'response' => $mwHttpRequest->getContent() ], $context, false, true
				);
			} );

		$this->assertNull(
			$this->getObjectUnderTest( $mockLogger )->getIPoidDataForIp( $ip, __METHOD__ ),
			'Should return null if the response from IPoid was not an array'
		);
		$this->assertTimingObserved( __METHOD__ );
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

		$this->assertNull(
			$this->getObjectUnderTest( $mockLogger )->getIPoidDataForIp( '1.2.3.4', __METHOD__ ),
			'Should return null if IP was not present in response from IPoid'
		);
		$this->assertTimingObserved( __METHOD__ );
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

		$this->assertNull(
			$this->getObjectUnderTest( $mockLogger )->getIPoidDataForIp(
				'1.2.3.4', __METHOD__
			),
			'Should return null if IP was not present in response from IPoid'
		);
		$this->assertTimingObserved( __METHOD__ );
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
}
