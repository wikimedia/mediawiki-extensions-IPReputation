<?php

namespace MediaWiki\Extension\IPReputation\Tests\Integration;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\IPReputation\PreAuthenticationProvider;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Request\WebRequest;
use MediaWiki\Tests\Unit\Auth\AuthenticationProviderTestTrait;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use MWHttpRequest;
use StatusValue;
use Wikimedia\Stats\Metrics\CounterMetric;

/**
 * @covers \MediaWiki\Extension\IPReputation\PreAuthenticationProvider
 * @covers \MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup
 * @group Database
 */
class PreAuthenticationProviderTest extends MediaWikiIntegrationTestCase {
	use MockHttpTrait;
	use AuthenticationProviderTestTrait;

	protected function setUp(): void {
		parent::setUp();
		// Reset the $wgIPReputationIPoidUrl back to the default value, in case a local development environment
		// has a different URL.
		$this->overrideConfigValue( 'IPReputationIPoidUrl', 'http://localhost:6035' );
	}

	private function getObjectUnderTest( $overrides = [] ): PreAuthenticationProvider {
		return new PreAuthenticationProvider(
			$overrides['ipReputationIPoidDataLookup'] ?? $this->getServiceContainer()->get(
				'IPReputationIPoidDataLookup'
			),
			$overrides['statsFactory'] ?? $this->getServiceContainer()->getStatsFactory(),
			$overrides['permissionManager'] ?? $this->getServiceContainer()->getPermissionManager()
		);
	}

	/**
	 * Convenience function to assert that the per-wiki IPReputation counter was incremented exactly once.
	 *
	 * @param string[] $expectedLabels Optional list of additional expected label values.
	 * @return void
	 */
	private function assertCounterIncremented( array $expectedLabels = [] ): void {
		$metric = $this->getServiceContainer()
			->getStatsFactory()
			->withComponent( 'IPReputation' )
			->getCounter( 'deny_account_creation' );

		$samples = $metric->getSamples();

		$this->assertInstanceOf( CounterMetric::class, $metric );
		$this->assertSame( 1, $metric->getSampleCount() );
		$this->assertSame( 1.0, $samples[0]->getValue() );

		$wikiId = WikiMap::getCurrentWikiId();
		$expectedLabels = array_merge(
			[ 'wiki' => rtrim( strtr( $wikiId, [ '-' => '_' ] ), '_' ) ],
			$expectedLabels
		);

		$actualLabels = array_combine( $metric->getLabelKeys(), $samples[0]->getLabelValues() );
		$this->assertSame( $expectedLabels, $actualLabels );
	}

	/**
	 * Convenience function to assert that the IPReputation metric counter was not incremented.
	 * @return void
	 */
	private function assertCounterNotIncremented(): void {
		$metric = $this->getServiceContainer()
			->getStatsFactory()
			->withComponent( 'IPReputation' )
			->getCounter( 'deny_account_creation' );

		$this->assertInstanceOf( CounterMetric::class, $metric );
		$this->assertSame( 0, $metric->getSampleCount() );
	}

	public function testTestForAccountCreationDenyIfIPMatchButNoRisksOrTunnels() {
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$status = new StatusValue();
		$status->setOK( true );
		$mwHttpRequest->method( 'execute' )
			->willReturn( $status );
		$ip = '1.2.3.4';
		$mwHttpRequest->method( 'getContent' )
			->willReturn( json_encode( [ $ip => [ 'data' ] ] ) );
		$this->installMockHttp( $mwHttpRequest );
		$request = $this->createMock( WebRequest::class );
		$request->method( 'getIP' )->willReturn( $ip );
		$provider = $this->getObjectUnderTest();
		$authManager = $this->createMock( AuthManager::class );
		$authManager->method( 'getRequest' )->willReturn( $request );
		$this->initProvider(
			$provider,
			new HashConfig( [
				'IPReputationIPoidCheckAtAccountCreation' => true,
				'IPReputationIPoidCheckAtAccountCreationLogOnly' => false,
				'IPReputationIPoidDenyAccountCreationRiskTypes' => [ 'CALLBACK_PROXY', 'UNKNOWN' ],
				'IPReputationIPoidDenyAccountCreationTunnelTypes' => [ 'PROXY' ],
			] ),
			null,
			$authManager
		);
		$this->assertStatusNotGood(
			$provider->testForAccountCreation(
				$this->createMock( User::class ),
				$this->createMock( User::class ),
				[]
			),
			'Return fatal status if IP matches'
		);
		$this->assertCounterIncremented( [ 'log_only' => '0', 'risk_unknown' => '1' ] );
	}

	public function testTestForAccountCreationMalformedData() {
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$status = new StatusValue();
		$status->setOK( true );
		$mwHttpRequest->method( 'execute' )
			->willReturn( $status );
		$ip = '1.2.3.4';
		$mwHttpRequest->method( 'getContent' )
			->willReturn( 'foo' );
		$this->installMockHttp( $mwHttpRequest );
		$request = $this->createMock( WebRequest::class );
		$request->method( 'getIP' )->willReturn( $ip );
		$provider = $this->getObjectUnderTest();
		$authManager = $this->createMock( AuthManager::class );
		$authManager->method( 'getRequest' )->willReturn( $request );
		$this->initProvider(
			$provider,
			new HashConfig( [
				'IPReputationIPoidCheckAtAccountCreation' => true,
			] ),
			null,
			$authManager
		);
		$this->assertStatusGood(
			$provider->testForAccountCreation(
				$this->createMock( User::class ),
				$this->createMock( User::class ),
				[]
			),
			'Return good status if malformed data'
		);
		$this->assertCounterNotIncremented();
	}

	public function testTestForAccountCreationIPNotInData() {
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$status = new StatusValue();
		$status->setOK( true );
		$mwHttpRequest->method( 'execute' )
			->willReturn( $status );
		$ip = '1.2.3.4';
		$mwHttpRequest->method( 'getContent' )
			->willReturn( json_encode( [ 'foo' => 'bar' ] ) );
		$this->installMockHttp( $mwHttpRequest );
		$request = $this->createMock( WebRequest::class );
		$request->method( 'getIP' )->willReturn( $ip );
		$provider = $this->getObjectUnderTest();
		$authManager = $this->createMock( AuthManager::class );
		$authManager->method( 'getRequest' )->willReturn( $request );
		$this->initProvider(
			$provider,
			new HashConfig( [
				'IPReputationIPoidCheckAtAccountCreation' => true,
			] ),
			null,
			$authManager
		);
		$this->assertStatusGood(
			$provider->testForAccountCreation(
				$this->createMock( User::class ),
				$this->createMock( User::class ),
				[]
			),
			'Return good status if IP is not in returned data'
		);
		$this->assertCounterNotIncremented();
	}

	public function testTestForAccountCreationWhenIPKnownButNoMatchToBlockedAttributes() {
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$status = new StatusValue();
		$status->setOK( true );
		$mwHttpRequest->method( 'execute' )
			->willReturn( $status );
		$ip = '1.2.3.4';
		$mwHttpRequest->method( 'getContent' )
			->willReturn( json_encode( [ '1.2.3.4' => [
				'risks' => [ 'TUNNEL' ],
				'tunnels' => [ 'PROXY' ]
			] ] ) );
		$this->installMockHttp( $mwHttpRequest );
		$request = $this->createMock( WebRequest::class );
		$request->method( 'getIP' )->willReturn( $ip );
		$provider = $this->getObjectUnderTest();
		$authManager = $this->createMock( AuthManager::class );
		$authManager->method( 'getRequest' )->willReturn( $request );
		$this->initProvider(
			$provider,
			new HashConfig( [
				'IPReputationIPoidCheckAtAccountCreation' => true,
				'IPReputationIPoidDenyAccountCreationRiskTypes' => [ 'GEO_MISMATCH' ],
				'IPReputationIPoidDenyAccountCreationTunnelTypes' => [ 'VPN' ],
				'IPReputationIPoidCheckAtAccountCreationLogOnly' => false,
			] ),
			null,
			$authManager
		);
		$this->assertStatusGood(
			$provider->testForAccountCreation(
				$this->createMock( User::class ),
				$this->createMock( User::class ),
				[]
			),
			'Returns a good status if risk and tunnel types for IP do not match any that are blocked.'
		);
		$this->assertCounterNotIncremented();
	}

	public function testTestForAccountCreationTunnelType() {
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$status = new StatusValue();
		$status->setOK( true );
		$mwHttpRequest->method( 'execute' )
			->willReturn( $status );
		$ip = '1.2.3.4';
		$mwHttpRequest->method( 'getContent' )
			->willReturn( json_encode( [ '1.2.3.4' => [
				'risks' => [ 'TUNNEL' ],
				'tunnels' => [ 'PROXY' ]
			] ] ) );
		$this->installMockHttp( $mwHttpRequest );
		$request = $this->createMock( WebRequest::class );
		$request->method( 'getIP' )->willReturn( $ip );
		$provider = $this->getObjectUnderTest();
		$authManager = $this->createMock( AuthManager::class );
		$authManager->method( 'getRequest' )->willReturn( $request );
		$this->initProvider(
			$provider,
			new HashConfig( [
				'IPReputationIPoidCheckAtAccountCreation' => true,
				'IPReputationIPoidDenyAccountCreationRiskTypes' => [ 'TUNNEL' ],
				'IPReputationIPoidDenyAccountCreationTunnelTypes' => [ 'PROXY' ],
				'IPReputationIPoidCheckAtAccountCreationLogOnly' => false,
			] ),
			null,
			$authManager
		);
		$this->assertStatusNotGood(
			$provider->testForAccountCreation(
				$this->createMock( User::class ),
				$this->createMock( User::class ),
				[]
			),
			'Return bad status if IP is a proxy tunnel and configured to deny those types.'
		);
		$this->assertCounterIncremented( [ 'log_only' => '0', 'risk_tunnel' => '1' ] );
	}

	public function testTestForAccountCreationTunnelTypeButLogOnly() {
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$status = new StatusValue();
		$status->setOK( true );
		$mwHttpRequest->method( 'execute' )
			->willReturn( $status );
		$ip = '1.2.3.4';
		$mwHttpRequest->method( 'getContent' )
			->willReturn( json_encode( [ '1.2.3.4' => [
				'risks' => [ 'TUNNEL' ],
				'tunnels' => [ 'PROXY' ]
			] ] ) );
		$this->installMockHttp( $mwHttpRequest );
		$request = $this->createMock( WebRequest::class );
		$request->method( 'getIP' )->willReturn( $ip );
		$provider = $this->getObjectUnderTest();
		$authManager = $this->createMock( AuthManager::class );
		$authManager->method( 'getRequest' )->willReturn( $request );
		$this->initProvider(
			$provider,
			new HashConfig( [
				'IPReputationIPoidCheckAtAccountCreation' => true,
				'IPReputationIPoidDenyAccountCreationRiskTypes' => [ 'TUNNEL' ],
				'IPReputationIPoidDenyAccountCreationTunnelTypes' => [ 'PROXY' ],
				'IPReputationIPoidCheckAtAccountCreationLogOnly' => true,
			] ),
			null,
			$authManager
		);
		$this->assertStatusGood(
			$provider->testForAccountCreation(
				$this->createMock( User::class ),
				$this->createMock( User::class ),
				[]
			),
			'Should return a good status if IPReputation is set to log only and not block account creation.'
		);
		$this->assertCounterIncremented( [ 'log_only' => '1', 'risk_tunnel' => '1' ] );
	}

	public function testTestForAccountCreationTunnelTypeAllowVPNIfDesired() {
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$status = new StatusValue();
		$status->setOK( true );
		$mwHttpRequest->method( 'execute' )
			->willReturn( $status );
		$ip = '1.2.3.4';
		$mwHttpRequest->method( 'getContent' )
			->willReturn( json_encode( [ '1.2.3.4' => [
				'risks' => [ 'TUNNEL' ],
				'tunnels' => [ 'VPN' ]
			] ] ) );
		$this->installMockHttp( $mwHttpRequest );
		$request = $this->createMock( WebRequest::class );
		$request->method( 'getIP' )->willReturn( $ip );
		$provider = $this->getObjectUnderTest();
		$authManager = $this->createMock( AuthManager::class );
		$authManager->method( 'getRequest' )->willReturn( $request );
		$this->initProvider(
			$provider,
			new HashConfig( [
				'IPReputationIPoidCheckAtAccountCreation' => true,
				'IPReputationIPoidDenyAccountCreationRiskTypes' => [ 'TUNNEL', 'GEO_MISMATCH' ],
				'IPReputationIPoidDenyAccountCreationTunnelTypes' => [ 'PROXY' ],
				'IPReputationIPoidCheckAtAccountCreationLogOnly' => true,
			] ),
			null,
			$authManager
		);
		$this->assertStatusGood(
			$provider->testForAccountCreation(
				$this->createMock( User::class ),
				$this->createMock( User::class ),
				[]
			),
			'Return good status if IP is a VPN tunnel and app is configured to block only proxies.'
		);
		$this->assertCounterNotIncremented();
	}

	public function testTestForAccountCreationRiskTypesConfig() {
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$status = new StatusValue();
		$status->setOK( true );
		$mwHttpRequest->method( 'execute' )
			->willReturn( $status );
		$ip = '1.2.3.4';
		$mwHttpRequest->method( 'getContent' )
			->willReturn( json_encode( [ '1.2.3.4' => [
				'risks' => [ 'TUNNEL', 'GEO_MISMATCH' ],
				'tunnels' => [ 'PROXY' ]
			] ] ) );
		$this->installMockHttp( $mwHttpRequest );
		$request = $this->createMock( WebRequest::class );
		$request->method( 'getIP' )->willReturn( $ip );
		$provider = $this->getObjectUnderTest();
		$authManager = $this->createMock( AuthManager::class );
		$authManager->method( 'getRequest' )->willReturn( $request );
		$this->initProvider(
			$provider,
			new HashConfig( [
				'IPReputationIPoidCheckAtAccountCreation' => true,
				'IPReputationIPoidDenyAccountCreationRiskTypes' => [ 'GEO_MISMATCH' ],
				'IPReputationIPoidDenyAccountCreationTunnelTypes' => [ 'PROXY' ],
				'IPReputationIPoidCheckAtAccountCreationLogOnly' => false,
			] ),
			null,
			$authManager
		);
		$this->assertStatusNotGood(
			$provider->testForAccountCreation(
				$this->createMock( User::class ),
				$this->createMock( User::class ),
				[]
			),
			'Return bad status if IP matches configured risk types'
		);
		$this->assertCounterIncremented( [ 'log_only' => '0', 'risk_geo_mismatch' => '1', 'risk_tunnel' => '1' ] );
	}

	public function testTestForAccountCreationDoNothingWithoutIPoidUrl() {
		$this->overrideConfigValue( 'IPReputationIPoidUrl', null );
		$this->setService( 'HttpRequestFactory', $this->createNoOpMock( HttpRequestFactory::class ) );
		$request = $this->createMock( WebRequest::class );
		$request->method( 'getIP' )->willReturn( '127.0.0.1' );
		$authManager = $this->createMock( AuthManager::class );
		$authManager->method( 'getRequest' )->willReturn( $request );
		$provider = $this->getObjectUnderTest();
		$this->initProvider(
			$provider,
			new HashConfig( [
				'IPReputationIPoidCheckAtAccountCreation' => true,
			] ),
			null,
			$authManager
		);
		$this->assertStatusGood(
			$provider->testForAccountCreation(
				$this->createMock( User::class ),
				$this->createMock( User::class ),
				[]
			),
			'Do nothing if IPoid URL is not set'
		);
		$this->assertCounterNotIncremented();
	}

	public function testTestForAccountCreationDoNothingWithoutFeatureFlag() {
		$this->setService( 'HttpRequestFactory', $this->createNoOpMock( HttpRequestFactory::class ) );
		$provider = $this->getObjectUnderTest();
		$this->initProvider(
			$provider,
			new HashConfig( [
				'IPReputationIPoidCheckAtAccountCreation' => null,
			] )
		);
		$this->assertStatusGood(
			$provider->testForAccountCreation(
				$this->createMock( User::class ),
				$this->createMock( User::class ),
				[]
			),
			'Do nothing if feature flag is off'
		);
		$this->assertCounterNotIncremented();
	}

	public function testTestForAccountCreationDoNothingIfCreatorHasIPBlockExempt() {
		$this->setService( 'HttpRequestFactory', $this->createNoOpMock( HttpRequestFactory::class ) );
		$provider = $this->getObjectUnderTest();
		$this->initProvider(
			$provider,
			new HashConfig( [
				'IPReputationIPoidCheckAtAccountCreation' => true,
			] )
		);
		$creator = $this->getTestUser( [ 'sysop' ] )->getUser();
		$this->assertStatusGood(
			$provider->testForAccountCreation(
				$this->createMock( User::class ),
				$creator,
				[]
			),
			'Do nothing if the creator has the ipblock-exempt right.'
		);
		$this->assertCounterNotIncremented();
	}

	public function testTestForAccountCreationDoNothingIfIPoidHasNoMatch() {
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$status = new StatusValue();
		$status->setOK( false );
		$mwHttpRequest->method( 'execute' )
			->willReturn( $status );
		$this->installMockHttp( $mwHttpRequest );
		$request = $this->createMock( WebRequest::class );
		$request->method( 'getIP' )->willReturn( '1.2.3.4' );
		$provider = $this->getObjectUnderTest();
		$authManager = $this->createMock( AuthManager::class );
		$authManager->method( 'getRequest' )->willReturn( $request );
		$this->initProvider(
			$provider,
			new HashConfig( [
				'IPReputationIPoidCheckAtAccountCreation' => true,
			] ),
			null,
			$authManager
		);
		$this->assertStatusGood(
			$provider->testForAccountCreation(
				$this->createMock( User::class ),
				$this->createMock( User::class ),
				[]
			),
			'Do nothing if IPoid has no match'
		);
		$this->assertCounterNotIncremented();
	}
}
