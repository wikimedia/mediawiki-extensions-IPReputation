<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\IPReputation\IPoid\IPoidDataFetcher;
use MediaWiki\Extension\IPReputation\IPoid\NodeJsIPoidDataFetcher;
use MediaWiki\Extension\IPReputation\IPoid\NullDataFetcher;
use MediaWiki\Extension\IPReputation\IPoid\OpenSearchIPoidDataFetcher;
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
			$services->getStatsFactory(),
			$services->getMainWANObjectCache(),
			$services->get( '_IPReputationIPoidDataFetcher' )
		);
	},

	'_IPReputationIPoidDataFetcher' => static function (
		MediaWikiServices $services
	): IPoidDataFetcher {
		$config = $services->getMainConfig();
		$dataProvider = $services->getMainConfig()->get( 'IPReputationDataProvider' );
		$ipoidUrl = $services->getMainConfig()->get( 'IPReputationIPoidUrl' );
		$logger = LoggerFactory::getInstance( 'IPReputation' );
		if ( !$ipoidUrl ) {
			return new NullDataFetcher( $logger );
		}
		return match ( $dataProvider ) {
			'nodejs_ipoid' => new NodeJsIPoidDataFetcher(
				new ServiceOptions( NodeJsIPoidDataFetcher::CONSTRUCTOR_OPTIONS, $config ),
				$services->getHttpRequestFactory(),
				$services->getFormatterFactory(),
				$logger
			),
			'opensearch_ipoid' => new OpenSearchIPoidDataFetcher(
				new ServiceOptions( OpenSearchIPoidDataFetcher::CONSTRUCTOR_OPTIONS, $config ),
				$services->getHttpRequestFactory(),
				$services->getFormatterFactory(),
				$logger
			),
			default => new NullDataFetcher( $logger )
		};
	}
];
// @codeCoverageIgnoreEnd
