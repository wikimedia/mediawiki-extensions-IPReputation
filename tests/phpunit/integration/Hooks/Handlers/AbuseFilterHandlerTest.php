<?php

namespace MediaWiki\Extension\IPReputation\Tests\Integration\Hooks\Handlers;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Tests\Integration\FilterFromSpecsTestTrait;
use MediaWiki\Extension\AbuseFilter\Variables\LazyLoadedVariable;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\IPReputation\Hooks\Handlers\AbuseFilterHandler;
use MediaWiki\Extension\IPReputation\IPoidResponse;
use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;
use MockTitleTrait;

/**
 * @covers \MediaWiki\Extension\IPReputation\Hooks\Handlers\AbuseFilterHandler
 * @group Database
 */
class AbuseFilterHandlerTest extends MediaWikiIntegrationTestCase {
	use FilterFromSpecsTestTrait;
	use MockTitleTrait;
	use TempUserTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'Abuse Filter' );
	}

	public function testAllIPReputationVariablesAreProtected() {
		$actualProtectedVariables = AbuseFilterServices::getProtectedVariablesLookup( $this->getServiceContainer() )
			->getAllProtectedVariables();
		foreach ( AbuseFilterHandler::SUPPORTED_VARIABLES as $variable ) {
			$this->assertContains( $variable, $actualProtectedVariables );
		}
	}

	public function testAllIPReputationVariablesHaveDocumentationDefined() {
		$actualBuilderValues = AbuseFilterServices::getKeywordsManager( $this->getServiceContainer() )
			->getBuilderValues();
		foreach ( AbuseFilterHandler::SUPPORTED_VARIABLES as $variable ) {
			$this->assertArrayHasKey(
				$variable,
				$actualBuilderValues['vars'],
				'Missing IPReputation AbuseFilter variable from builder description'
			);
			$this->assertSame(
				str_replace( '_', '-', $variable ),
				$actualBuilderValues['vars'][$variable],
				"Builder message key for $variable is not as expected"
			);
		}
	}

	/**
	 * Gets the user variables for a given user for testing that the IPReputation AbuseFilter variables are
	 * correctly set for this user.
	 *
	 * @param string $username Username or IP
	 * @param RecentChange|null $recentChange Provide if the action is historical (doing this should mean the
	 *   IP comes from the RecentChange object and not the main request).
	 * @return VariableHolder
	 */
	private function getUserVariablesForUser( string $username, ?RecentChange $recentChange = null ) {
		$userObj = $this->getServiceContainer()->getUserFactory()
			->newFromName( $username, UserFactory::RIGOR_NONE );
		$runVariableGenerator = AbuseFilterServices::getVariableGeneratorFactory()
			->newRunGenerator( $userObj, $this->makeMockTitle( 'Test' ) );
		$runVariableGenerator->addUserVars( $userObj, $recentChange );
		return $runVariableGenerator->getVariableHolder();
	}

	/**
	 * Asserts that a given {@link VariableHolder} has the provided value for the provided variable.
	 *
	 * @param mixed $expected
	 * @param string $variable
	 * @param VariableHolder $variableHolder
	 * @return void
	 */
	private function assertVariableHasValue( $expected, string $variable, VariableHolder $variableHolder ) {
		$actualValue = $variableHolder->getVarThrow( $variable );
		if ( $actualValue instanceof LazyLoadedVariable ) {
			$actualValue = AbuseFilterServices::getLazyVariableComputer()
				->compute( $actualValue, $variableHolder, static fn () => 'unused' );
		}
		$actualValue = $actualValue->toNative();

		$this->assertSame(
			$expected, $actualValue,
			"Value for AbuseFilter variable $variable was not as expected"
		);
	}

	public function testAbuseFilterHitUsingIPReputationVariablesWhenIPNotKnown() {
		// Mock that IPoid does not know the IP 1.2.3.4
		$ip = '1.2.3.4';
		$mockIPoidDataLookup = $this->createMock( IPReputationIPoidDataLookup::class );
		$mockIPoidDataLookup->method( 'getIPoidDataForIp' )
			->with( $ip )
			->willReturn( null );
		$this->setService( 'IPReputationIPoidDataLookup', $mockIPoidDataLookup );

		// Get the variables for the IP 1.2.3.4
		RequestContext::getMain()->getRequest()->setIP( $ip );
		$varHolder = $this->getUserVariablesForUser( $ip );

		// Check that the user vars include all IPReputation AbuseFilter variables and that the values are as
		// expected (all null as the IP is not known except ip_reputation_ipoid_known which should be false).
		foreach ( AbuseFilterHandler::SUPPORTED_VARIABLES as $variable ) {
			$this->assertVariableHasValue(
				$variable === 'ip_reputation_ipoid_known' ? false : null, $variable, $varHolder
			);
		}
	}

	/**
	 * Mocks that IPReputation data exists for the given $ip.
	 *
	 * @param string $ip
	 * @return IPoidResponse The mock data
	 */
	private function mockThatIPReputationDataExists( string $ip ): IPoidResponse {
		$response = IPoidResponse::newFromArray( [
			'tunnels' => [ 'TUNNEL' ],
			'risks' => [ 'RISK' ],
			'proxies' => [ 'PROXY' ],
			'behaviors' => [ 'BEHAVIOUR' ],
			'client_count' => 123,
		] );
		$mockIPoidDataLookup = $this->createMock( IPReputationIPoidDataLookup::class );
		$mockIPoidDataLookup->method( 'getIPoidDataForIp' )
			->with( $ip )
			->willReturn( $response );
		$this->setService( 'IPReputationIPoidDataLookup', $mockIPoidDataLookup );
		return $response;
	}

	/**
	 * Asserts that the provided {@link IPoidResponse} data is the data used to fill the IPReputation AbuseFilter
	 * variables.
	 *
	 * @param IPoidResponse $response
	 * @param VariableHolder $varHolder
	 * @return void
	 */
	private function assertThatVariablesMatchIPoidResponseObject(
		IPoidResponse $response, VariableHolder $varHolder
	) {
		$this->assertVariableHasValue( $response->getTunnelOperators(), 'ip_reputation_tunnel_operators', $varHolder );
		$this->assertVariableHasValue( $response->getRisks(), 'ip_reputation_risk_types', $varHolder );
		$this->assertVariableHasValue( $response->getProxies(), 'ip_reputation_client_proxies', $varHolder );
		$this->assertVariableHasValue( $response->getBehaviors(), 'ip_reputation_client_behaviors', $varHolder );
		$this->assertVariableHasValue( $response->getNumUsersOnThisIP(), 'ip_reputation_client_count', $varHolder );
		$this->assertVariableHasValue( true, 'ip_reputation_ipoid_known', $varHolder );
	}

	public function testIPReputationVariablesAreSetWhenIPKnown() {
		$ip = '1.2.3.4';
		$response = $this->mockThatIPReputationDataExists( $ip );

		// Get the variables for the IP 1.2.3.4 and assert that the values are correct.
		RequestContext::getMain()->getRequest()->setIP( $ip );
		$varHolder = $this->getUserVariablesForUser( $ip );
		$this->assertThatVariablesMatchIPoidResponseObject( $response, $varHolder );

		// Test that other variables can be fetched as normal with IPReputation installed (specifically that the hook
		// handler returns early if the variable is not an IPReputation variable).
		$this->assertVariableHasValue( 'ip', 'user_type', $varHolder );
	}

	public function testIPReputationVariablesAreSetWhenIPKnownForTemporaryAccount() {
		// Fix the naming format so that we can test using a temporary account name without having to create
		// a real temporary account.
		$this->enableAutoCreateTempUser( [ 'genPattern' => '~$1' ] );

		$ip = '1.2.3.4';
		$response = $this->mockThatIPReputationDataExists( $ip );

		// Get the variables when using a temporary account performer and assert that the values are correct.
		RequestContext::getMain()->getRequest()->setIP( $ip );
		$varHolder = $this->getUserVariablesForUser( '~2025-1' );
		$this->assertThatVariablesMatchIPoidResponseObject( $response, $varHolder );
	}

	public function testIPReputationVariablesAreSetWhenCreatingANamedAccount() {
		$ip = '1.2.3.4';
		$response = $this->mockThatIPReputationDataExists( $ip );

		// Get the variables when using a temporary account performer and assert that the values are correct.
		RequestContext::getMain()->getRequest()->setIP( $ip );
		$createdUser = $this->getTestUser()->getUser();
		$runVariableGenerator = AbuseFilterServices::getVariableGeneratorFactory()
			->newRunGenerator( $createdUser, $this->makeMockTitle( 'Test' ) );
		$varHolder = $runVariableGenerator->getAccountCreationVars( $createdUser, false );
		$this->assertThatVariablesMatchIPoidResponseObject( $response, $varHolder );
	}

	public function testIPReputationVariablesForRecentChangeWhenPutIPinRCIsTrue() {
		$this->overrideConfigValue( MainConfigNames::PutIPinRC, true );

		$ip = '1.2.3.4';
		$response = $this->mockThatIPReputationDataExists( $ip );

		$this->disableAutoCreateTempUser();
		RequestContext::getMain()->getRequest()->setIP( $ip );
		$pageUpdateStatus = $this->editPage(
			$this->getNonexistingTestPage(), 'Testingabc', '', NS_MAIN,
			new UltimateAuthority( UserIdentityValue::newAnonymous( $ip ) )
		);
		$this->assertStatusGood( $pageUpdateStatus );
		$recentChange = RecentChange::newFromConds( [
			'rc_this_oldid' => $pageUpdateStatus->getNewRevision()->getId(),
		] );

		// Change the IP so that we can assert that when providing a RecentChange object we don't use the main
		// request IP.
		RequestContext::getMain()->getRequest()->setIP( '5.6.7.8' );

		// Get the variables when the query is for a RecentChanges entry and assert that the variables values
		// are correct.
		$varHolder = $this->getUserVariablesForUser( $ip, $recentChange );
		$this->assertThatVariablesMatchIPoidResponseObject( $response, $varHolder );

		// Test that other variables can be fetched as normal with IPReputation installed (specifically that the hook
		// handler returns early if the variable is not an IPReputation variable).
		$this->assertVariableHasValue( 'ip', 'user_type', $varHolder );
	}

	public function testIPReputationVariablesForRecentChangeWhenPutIPinRCIsFalse() {
		// Make an edit using an IP while $wgPutIPinRC is false (so the recentchanges entry has no IP)
		$this->overrideConfigValue( MainConfigNames::PutIPinRC, false );
		$ip = '1.2.3.4';
		$this->disableAutoCreateTempUser();
		RequestContext::getMain()->getRequest()->setIP( $ip );
		$pageUpdateStatus = $this->editPage(
			$this->getNonexistingTestPage(), 'Testingabc', '', NS_MAIN,
			new UltimateAuthority( UserIdentityValue::newAnonymous( $ip ) )
		);
		$this->assertStatusGood( $pageUpdateStatus );
		$recentChange = RecentChange::newFromConds( [
			'rc_this_oldid' => $pageUpdateStatus->getNewRevision()->getId(),
		] );

		// If no IP is in the recentchanges table, then we should not be able to attempt to fetch IPReputation data.
		$this->testIPReputationVariablesWhenDataShouldNotBeFetched( $ip, $recentChange );
	}

	/** @dataProvider provideIPReputationVariablesWhenDataShouldNotBeFetched */
	public function testIPReputationVariablesWhenDataShouldNotBeFetched(
		string $user, ?RecentChange $recentChange = null
	) {
		$this->setService(
			'IPReputationIPoidDataLookup', $this->createNoOpMock( IPReputationIPoidDataLookup::class )
		);

		$varHolder = $this->getUserVariablesForUser( $user, $recentChange );

		foreach ( AbuseFilterHandler::SUPPORTED_VARIABLES as $variable ) {
			$this->assertVariableHasValue( null, $variable, $varHolder );
		}
	}

	public static function provideIPReputationVariablesWhenDataShouldNotBeFetched() {
		return [
			'Performer is a named account' => [ 'TestUser' ],
			'Performer is an IP range' => [ '1.2.3.0/24' ],
		];
	}

	/**
	 * @dataProvider provideOnAccountCreationWhenIPReputationVariablesShouldNotBeSet
	 *
	 * @param bool $autocreated If the created account was autocreated
	 * @param UserIdentity|null $creator The performer of the account creation. If null, then $createdAccount is used.
	 * @param UserIdentity|null $createdUser The created user. If null, then a sysop account.
	 */
	public function testOnAccountCreationWhenIPReputationVariablesShouldNotBeSet(
		bool $autocreated, ?UserIdentity $creator = null, ?UserIdentity $createdUser = null
	): void {
		// Fix the naming format so that we can test using a temporary account name without having to create
		// a real temporary account.
		$this->enableAutoCreateTempUser( [ 'genPattern' => '~$1' ] );

		// Expect no calls to attempt to get data if they should not be used in the AbuseFilter variables.
		$this->setService(
			'IPReputationIPoidDataLookup', $this->createNoOpMock( IPReputationIPoidDataLookup::class )
		);

		$createdUser = $this->getServiceContainer()->getUserFactory()->newFromUserIdentity(
			$createdUser ?? $this->getTestUser()->getUser()
		);
		$creator = $this->getServiceContainer()->getUserFactory()->newFromUserIdentity(
			$creator ?? $createdUser
		);

		// Check that the AbuseFilter variables are empty for these conditions.
		$runVariableGenerator = AbuseFilterServices::getVariableGeneratorFactory()
			->newRunGenerator( $creator, $this->makeMockTitle( 'Test' ) );
		$varHolder = $runVariableGenerator->getAccountCreationVars( $createdUser, $autocreated );
		foreach ( AbuseFilterHandler::SUPPORTED_VARIABLES as $variable ) {
			$this->assertVariableHasValue( null, $variable, $varHolder );
		}
	}

	public static function provideOnAccountCreationWhenIPReputationVariablesShouldNotBeSet() {
		return [
			'Account creation is an autocreation of a named user' => [ true, null ],
			'Account was created by a different registered user' => [
				false, new UserIdentityValue( 1, 'TestUser' ),
			],
		];
	}

	/**
	 * @dataProvider provideOnAccountCreationWhenIPReputationVariablesShouldBeSet
	 *
	 * @param bool $autocreated If the created account was autocreated
	 * @param UserIdentity $creator The performer of the account creation.
	 * @param UserIdentity $createdUser The created user.
	 */
	public function testOnAccountCreationWhenIPReputationVariablesShouldBeSet(
		bool $autocreated, UserIdentity $creator, UserIdentity $createdUser
	): void {
		// Fix the naming format so that we can test using a temporary account name without having to create
		// a real temporary account.
		$this->enableAutoCreateTempUser( [ 'genPattern' => '~$1' ] );

		$ip = '1.2.3.4';
		$response = $this->mockThatIPReputationDataExists( $ip );
		RequestContext::getMain()->getRequest()->setIP( $ip );

		$createdUser = $this->getServiceContainer()->getUserFactory()->newFromUserIdentity( $createdUser );
		$creator = $this->getServiceContainer()->getUserFactory()->newFromUserIdentity( $creator );

		// Check that the AbuseFilter variables are empty for these conditions.
		$runVariableGenerator = AbuseFilterServices::getVariableGeneratorFactory()
			->newRunGenerator( $creator, $this->makeMockTitle( 'Test' ) );
		$varHolder = $runVariableGenerator->getAccountCreationVars( $createdUser, $autocreated );

		$this->assertThatVariablesMatchIPoidResponseObject( $response, $varHolder );
	}

	public static function provideOnAccountCreationWhenIPReputationVariablesShouldBeSet() {
		return [
			'An autocreation of a temporary account' => [
				true, new UserIdentityValue( 1, '~2025-1' ),
				new UserIdentityValue( 1, '~2025-1' ),
			],
			'A creation of a named account when performer is the new account' => [
				false, new UserIdentityValue( 1, 'Test' ),
				new UserIdentityValue( 1, 'Test' ),
			],
			'A creation of a named account when performer is the an IP' => [
				false, UserIdentityValue::newAnonymous( '1.2.3.4' ),
				new UserIdentityValue( 1, 'Test' ),
			],
		];
	}

	public function testDevsUpdateTheseTests() {
		$this->assertArrayEquals(
			[
				'ip_reputation_tunnel_operators',
				'ip_reputation_risk_types',
				'ip_reputation_client_proxies',
				'ip_reputation_client_behaviors',
				'ip_reputation_client_count',
				'ip_reputation_ipoid_known',
			],
			AbuseFilterHandler::SUPPORTED_VARIABLES,
			false,
			false,
			'Please update these tests to test any new IPReputation AbuseFilter variables.'
		);
	}
}
