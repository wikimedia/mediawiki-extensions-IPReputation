<?php

namespace MediaWiki\Extension\IPReputation\Tests\Unit;

use MediaWiki\Extension\IPReputation\IPoid\IPoidResponse;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\IPReputation\IPoid\IPoidResponse
 */
class IPoidResponseTest extends MediaWikiUnitTestCase {

	/** @dataProvider provideNewFromArray */
	public function testNewFromArrayLoop( $inputArray, $expectedJsonSerializeReturnValue ) {
		$this->assertArrayEquals(
			$expectedJsonSerializeReturnValue,
			IPoidResponse::newFromArray( $inputArray )->jsonSerialize(),
			false, true
		);
	}

	public static function provideNewFromArray() {
		return [
			'Input array is empty' => [
				[],
				[
					'behaviors' => null, 'risks' => [ 'UNKNOWN' ],
					'tunnelOperators' => null, 'proxies' => null, 'numUsersOnThisIP' => null,
					'countries' => null,
				]
			],
			'Input array contains varied data' => [
				[
					'behaviors' => [ 'TOR_PROXY_USER' ],
					'tunnels' => [ 'PROXY' ],
					'proxies' => [ '3_PROXY', '1_PROXY' ],
					'client_count' => 10,
					'risks' => [ 'GEO_MISMATCH' ],
					'countries' => 2,
				],
				[
					'behaviors' => [ 'TOR_PROXY_USER' ],
					'tunnelOperators' => [ 'PROXY' ],
					'proxies' => [ '3_PROXY', '1_PROXY' ],
					'numUsersOnThisIP' => 10,
					'risks' => [ 'GEO_MISMATCH' ],
					'countries' => 2,
				]
			],
		];
	}
}
