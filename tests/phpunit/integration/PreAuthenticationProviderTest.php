<?php

namespace MediaWiki\Extension\IPReputation\Tests\Integration;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Config\HashConfig;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\IPReputation\IPoid\IPoidResponse;
use MediaWiki\Extension\IPReputation\PreAuthenticationProvider;
use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\Unit\Auth\AuthenticationProviderTestTrait;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\Stats\Metrics\CounterMetric;

/**
 * @covers \MediaWiki\Extension\IPReputation\PreAuthenticationProvider
 * @group Database
 */
class PreAuthenticationProviderTest extends MediaWikiIntegrationTestCase {
	use AuthenticationProviderTestTrait;

	protected function setUp(): void {
		parent::setUp();
		// Reset the $wgIPReputationIPoidUrl back to the default value, in case a local development environment
		// has a different URL.
		$this->overrideConfigValue( 'IPReputationIPoidUrl', 'http://localhost:6035' );
	}

	private function getObjectUnderTest( $mockIPoidDataLookup ): PreAuthenticationProvider {
		return new PreAuthenticationProvider(
			$mockIPoidDataLookup,
			$this->getServiceContainer()->getStatsFactory(),
			$this->getServiceContainer()->getPermissionManager()
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
			->getCounter( 'log_account_creation' );

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

	/**
	 * @dataProvider provideTestTestForAccountCreationIPMatch
	 */
	public function testTestForAccountCreationIPMatch( $data, $logValue ) {
		$ip = '1.2.3.4';
		$mockIPoidDataLookup = $this->createMock( IPReputationIPoidDataLookup::class );
		$mockIPoidDataLookup->method( 'getIPoidDataForIp' )
			->with( $ip )
			->willReturn( IPoidResponse::newFromArray( $data ) );
		$provider = $this->getObjectUnderTest( $mockIPoidDataLookup );

		$request = new FauxRequest();
		$request->setIP( $ip );
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
			'Always expect good status'
		);
		DeferredUpdates::doUpdates();
		$this->assertCounterIncremented( $logValue );
	}

	public static function provideTestTestForAccountCreationIPMatch() {
		return [
			'no data' => [
				'data' => [ 'data' ],
				'logValue' => [
					'risk_unknown' => '1'
				],
			],
			'risks' => [
				'data' => [
					'risks' => [
						'TUNNEL',
						'GEO_MISMATCH'
					],
				],
				'logValue' => [
					'risk_geo_mismatch' => '1',
					'risk_tunnel' => '1'
				],
			]
		];
	}

	public function testTestForAccountCreationOnNoIPoidData() {
		// Mock the return value from the IPReputationIPoidDataLookup service to indicate no data.
		$ip = '1.2.3.4';
		$mockIPoidDataLookup = $this->createMock( IPReputationIPoidDataLookup::class );
		$mockIPoidDataLookup->method( 'getIPoidDataForIp' )
			->with( $ip )
			->willReturn( null );
		$provider = $this->getObjectUnderTest( $mockIPoidDataLookup );

		$request = new FauxRequest();
		$request->setIP( $ip );
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
			'Return good status if no IPoid data.'
		);
		DeferredUpdates::doUpdates();
		$this->assertCounterNotIncremented();
	}

	public function testTestForAccountCreationDoNothingWithoutFeatureFlag() {
		$provider = $this->getObjectUnderTest( $this->createNoOpMock( IPReputationIPoidDataLookup::class ) );
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
		DeferredUpdates::doUpdates();
		$this->assertCounterNotIncremented();
	}

	public function testTestForAccountCreationDoNothingIfCreatorHasIPBlockExempt() {
		$provider = $this->getObjectUnderTest( $this->createNoOpMock( IPReputationIPoidDataLookup::class ) );
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
}
