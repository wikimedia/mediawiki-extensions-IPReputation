<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

// PHP unit does not understand code coverage for this file
// as the @covers annotation cannot cover a specific file
// This is fully tested in ServiceWiringTest.php
// @codeCoverageIgnoreStart

return [
	'IPReputationIPoidDataLookup' => static function (
		MediaWikiServices $services
	): IPReputationIPoidDataLookup {
		return new IPReputationIPoidDataLookup(
			new ServiceOptions(
				IPReputationIPoidDataLookup::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			$services->getFormatterFactory(),
			$services->getHttpRequestFactory(),
			$services->getMainWANObjectCache(),
			LoggerFactory::getInstance( 'IPReputation' )
		);
	},
];
// @codeCoverageIgnoreEnd
