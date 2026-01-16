<?php

namespace MediaWiki\Extension\IPReputation\Tests\Integration\Hooks\Handlers;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ConfirmEdit\CaptchaTriggers;
use MediaWiki\Extension\ConfirmEdit\SimpleCaptcha\SimpleCaptcha;
use MediaWiki\Extension\IPReputation\IPoid\IPoidResponse;
use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\IPReputation\Hooks\Handlers\ConfirmEditHandler
 */
class ConfirmEditHandlerTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );
	}

	/** @dataProvider provideOnConfirmEditTriggersCaptcha */
	public function testOnConfirmEditTriggersCaptcha(
		$captchaActionCallback, $configEnabled, $shouldExpectCallToIPoidDataLookup, $ipKnownToIpoid, $shouldShowCaptcha
	) {
		$ip = '1.2.3.4';
		RequestContext::getMain()->getRequest()->setIP( $ip );
		$this->overrideConfigValues( [
			'IPReputationEnableLoginCaptchaIfIPKnown' => $configEnabled,
			'CaptchaTriggers' => [],
		] );

		// Mock the return value from the IPReputationIPoidDataLookup::getIPoidDataForIp based on whether we want
		// to test having the IP known to IPoid.
		$mockIPoidDataLookup = $this->createMock( IPReputationIPoidDataLookup::class );
		$mockIPoidDataLookup->expects( $shouldExpectCallToIPoidDataLookup ? $this->once() : $this->never() )
			->method( 'getIPoidDataForIp' )
			->with( $ip )
			->willReturn( $ipKnownToIpoid ? IPoidResponse::newFromArray( [ 'data' ] ) : null );
		$this->setService( 'IPReputationIPoidDataLookup', $mockIPoidDataLookup );

		// Call Captcha::triggersCaptcha to check if the hook was correctly called and whether the expected
		// result (having or not having a captcha) is seen.
		$captcha = new SimpleCaptcha();
		$this->assertSame( $shouldShowCaptcha, $captcha->triggersCaptcha( $captchaActionCallback() ) );
	}

	public static function provideOnConfirmEditTriggersCaptcha() {
		// A callback for the action is needed because the data provider is loaded before setUp and therefore
		// would attempt to find a non-existing class.
		return [
			'Captcha action is not for a login' => [
				'captchaActionCallback' => static fn () => CaptchaTriggers::EDIT,
				'configEnabled' => true,
				'shouldExpectCallToIPoidDataLookup' => false,
				'ipKnownToIpoid' => true,
				'shouldShowCaptcha' => false,
			],
			'IPReputation not configured to show catpcha on known IP' => [
				'captchaActionCallback' => static fn () => CaptchaTriggers::LOGIN_ATTEMPT,
				'configEnabled' => false,
				'shouldExpectCallToIPoidDataLookup' => false,
				'ipKnownToIpoid' => true,
				'shouldShowCaptcha' => false,
			],
			'IPoid does not know the IP' => [
				'captchaActionCallback' => static fn () => CaptchaTriggers::LOGIN_ATTEMPT,
				'configEnabled' => true,
				'shouldExpectCallToIPoidDataLookup' => true,
				'ipKnownToIpoid' => false,
				'shouldShowCaptcha' => false,
			],
			'IPoid knows the IP when configured to show CAPTCHA' => [
				'captchaActionCallback' => static fn () => CaptchaTriggers::LOGIN_ATTEMPT,
				'configEnabled' => true,
				'shouldExpectCallToIPoidDataLookup' => true,
				'ipKnownToIpoid' => true,
				'shouldShowCaptcha' => true,
			],
		];
	}
}
