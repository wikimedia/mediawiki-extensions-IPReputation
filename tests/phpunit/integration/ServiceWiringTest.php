<?php

/**
 * Copy of CentralAuth's CentralAuthServiceWiringTest.php
 */

namespace MediaWiki\Extension\IPReputation\Tests\Integration;

use MediaWikiIntegrationTestCase;

/**
 * @coversNothing
 * @group Database
 */
class ServiceWiringTest extends MediaWikiIntegrationTestCase {
	/**
	 * @dataProvider provideService
	 */
	public function testService( string $name ) {
		$this->getServiceContainer()->get( $name );
		$this->addToAssertionCount( 1 );
	}

	public static function provideService() {
		$wiring = require __DIR__ . '/../../../src/ServiceWiring.php';
		foreach ( $wiring as $name => $_ ) {
			yield $name => [ $name ];
		}
	}
}
