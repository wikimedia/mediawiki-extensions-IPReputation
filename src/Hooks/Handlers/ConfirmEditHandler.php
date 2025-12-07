<?php

namespace MediaWiki\Extension\IPReputation\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ConfirmEdit\CaptchaTriggers;
use MediaWiki\Extension\ConfirmEdit\Hooks\ConfirmEditTriggersCaptchaHook;
use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWiki\Page\PageIdentity;

class ConfirmEditHandler implements ConfirmEditTriggersCaptchaHook {

	public function __construct(
		private readonly Config $config,
		private readonly IPReputationIPoidDataLookup $ipoidDataLookup,
	) {
	}

	public function onConfirmEditTriggersCaptcha( string $action, ?PageIdentity $page, bool &$result ) {
		// If this is a login, the config is enabled, and the result hasn't already been set to true
		// by someone else
		if (
			$action === CaptchaTriggers::LOGIN_ATTEMPT &&
			$this->config->get( 'IPReputationEnableLoginCaptchaIfIPKnown' ) &&
			!$result
		) {
			// If there's an entry in IPoid for the IP, show a CAPTCHA on login.
			$result = (bool)$this->ipoidDataLookup->getIPoidDataForIp(
				RequestContext::getMain()->getRequest()->getIP(),
				__METHOD__
			);
		}
	}
}
