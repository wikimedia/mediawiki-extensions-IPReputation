<?php

namespace MediaWiki\Extension\IPReputation\Tests\Integration;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\RawMessage;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\User\UserIdentity;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use MWHttpRequest;
use Psr\Log\LoggerInterface;
use StatusValue;

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

	private function getObjectUnderTest( $mockLogger = null ): IPReputationIPoidDataLookup {
		return new IPReputationIPoidDataLookup(
			new ServiceOptions(
				IPReputationIPoidDataLookup::CONSTRUCTOR_OPTIONS,
				$this->getServiceContainer()->getMainConfig()
			),
			$this->getServiceContainer()->getFormatterFactory(),
			$this->getServiceContainer()->getHttpRequestFactory(),
			$this->getServiceContainer()->getMainWANObjectCache(),
			$mockLogger ?? LoggerFactory::getInstance( 'IPReputation' )
		);
	}

	public function testGetIPoidDataForWhenIPoidUrlNotSet() {
		$this->overrideConfigValue( 'IPReputationIPoidUrl', null );

		// Expect no attempts to make a request to IPoid without a defined URL, and expect that a warning
		// is logged to indicate this issue.
		$this->setService( 'HttpRequestFactory', $this->createNoOpMock( HttpRequestFactory::class ) );
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'warning' )
			->with( 'Configured to check IP reputation on signup, but no IPoid URL configured' );

		$this->assertNull(
			$this->getObjectUnderTest( $mockLogger )->getIPoidDataFor(
				$this->createMock( UserIdentity::class ), '1.2.3.4'
			),
			'Should return null if IPoid URL was not defined'
		);
	}

	public function testGetIPoidDataForOnNonArrayResponse() {
		$this->overrideConfigValue( 'IPReputationIPoidRequestTimeoutSeconds', 10 );
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
		$mockUserIdentity = $this->createMock( UserIdentity::class );
		$mockUserIdentity->method( 'getName' )
			->willReturn( 'Test' );
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'error' )
			->with(
				'Got invalid JSON data while checking user {user} with IP {ip}',
				[ 'ip' => $ip, 'user' => 'Test', 'response' => $mwHttpRequest->getContent() ]
			);

		$this->assertNull(
			$this->getObjectUnderTest( $mockLogger )->getIPoidDataFor( $mockUserIdentity, $ip ),
			'Should return null if the response from IPoid was not an array'
		);
	}

	public function testGetIPoidDataForOnArrayResponseNotContainingIP() {
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
		$mockUserIdentity = $this->createMock( UserIdentity::class );
		$mockUserIdentity->method( 'getName' )
			->willReturn( 'Test' );
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'error' )
			->with(
				'Got JSON data with no IP {ip} present while checking user {user}',
				[ 'ip' => '1.2.3.4', 'user' => 'Test', 'response' => $mwHttpRequest->getContent() ]
			);

		$this->assertNull(
			$this->getObjectUnderTest( $mockLogger )->getIPoidDataFor( $mockUserIdentity, '1.2.3.4' ),
			'Should return null if IP was not present in response from IPoid'
		);
	}

	public function testGetIPoidDataForWhenIPoidReturnsWith500Error() {
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
			$this->getObjectUnderTest( $mockLogger )->getIPoidDataFor(
				$this->createMock( UserIdentity::class ), '1.2.3.4'
			),
			'Should return null if IP was not present in response from IPoid'
		);
	}

	public function testGetIPoidDataForWhenIPoidHasNoMatch() {
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
			$this->getObjectUnderTest( $mockLogger )->getIPoidDataFor(
				$this->createMock( UserIdentity::class ), '1.2.3.4'
			),
			'Should return null if IP was not present in response from IPoid'
		);
	}

	public function testGetIPoidDataForOnArrayResponse() {
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
			[
				'risks' => [ 'TUNNEL' ],
				'tunnels' => [ 'PROXY' ]
			],
			$this->getObjectUnderTest()->getIPoidDataFor(
				$this->createMock( UserIdentity::class ), '1.2.3.4'
			),
			'Should return array from IPoid if response is valid and the IP is known to IPoid'
		);
	}
}
