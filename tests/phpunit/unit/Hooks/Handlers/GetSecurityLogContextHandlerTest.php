<?php

namespace MediaWiki\Extension\IPReputation\Tests\Hooks\Handlers;

use MediaWiki\Extension\IPReputation\Hooks\Handlers\GetSecurityLogContextHandler;
use MediaWiki\Extension\IPReputation\IPoid\IPoidResponse;
use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWikiUnitTestCase;
use WebRequest;

/**
 * @covers \MediaWiki\Extension\IPReputation\Hooks\Handlers\GetSecurityLogContextHandler
 */
class GetSecurityLogContextHandlerTest extends MediaWikiUnitTestCase {

	public function testOnGetSecurityLogContext_PopulatesData() {
		$mockResponse = $this->createMock( IPoidResponse::class );
		$mockResponse->method( 'getTunnelOperators' )->willReturn( [ 'NordVPN' ] );
		$mockResponse->method( 'getRisks' )->willReturn( [ 'VPN_TUNNEL', 'GEO_MISMATCH' ] );
		$mockResponse->method( 'getProxies' )->willReturn( [ 'Cloudflare' ] );
		$mockResponse->method( 'getBehaviors' )->willReturn( [ 'CRAWLER' ] );

		$mockLookup = $this->createMock( IPReputationIPoidDataLookup::class );
		$mockLookup->method( 'getIPoidDataForIp' )
			->with( '1.2.3.4', $this->anything() )
			->willReturn( $mockResponse );

		$mockRequest = $this->createMock( WebRequest::class );
		$mockRequest->method( 'getIP' )->willReturn( '1.2.3.4' );

		$handler = new GetSecurityLogContextHandler( $mockLookup );
		$handler->enableHookHandlerForTest();
		$context = [];
		$info = [ 'request' => $mockRequest ];

		$handler->onGetSecurityLogContext( $info, $context );

		$this->assertEquals( [ 'NordVPN' ], $context['ip_reputation_tunnels'] );
		$this->assertEquals( [ 'VPN_TUNNEL', 'GEO_MISMATCH' ], $context['ip_reputation_risks'] );
		$this->assertEquals( [ 'Cloudflare' ], $context['ip_reputation_proxies'] );
		$this->assertEquals( [ 'CRAWLER' ], $context['ip_reputation_behaviors'] );
	}

	public function testOnGetSecurityLogContext_SkipsEmptyData() {
		$mockResponse = $this->createMock( IPoidResponse::class );
		$mockResponse->method( 'getTunnelOperators' )->willReturn( null );
		$mockResponse->method( 'getRisks' )->willReturn( [ 'CALLBACK_PROXY' ] );
		$mockResponse->method( 'getProxies' )->willReturn( [] );
		$mockResponse->method( 'getBehaviors' )->willReturn( null );

		$mockLookup = $this->createMock( IPReputationIPoidDataLookup::class );
		$mockLookup->method( 'getIPoidDataForIp' )->willReturn( $mockResponse );

		$mockRequest = $this->createMock( WebRequest::class );
		$mockRequest->method( 'getIP' )->willReturn( '1.2.3.4' );

		$handler = new GetSecurityLogContextHandler( $mockLookup );
		$handler->enableHookHandlerForTest();
		$context = [];
		$info = [ 'request' => $mockRequest ];

		$handler->onGetSecurityLogContext( $info, $context );

		$this->assertArrayHasKey( 'ip_reputation_risks', $context );
		$this->assertArrayNotHasKey( 'ip_reputation_tunnels', $context );
		$this->assertArrayNotHasKey( 'ip_reputation_proxies', $context );
		$this->assertArrayNotHasKey( 'ip_reputation_behaviors', $context );
	}

	public function testOnGetSecurityLogContext_HandlesNullResponse() {
		$mockLookup = $this->createMock( IPReputationIPoidDataLookup::class );
		$mockLookup->method( 'getIPoidDataForIp' )->willReturn( null );

		$mockRequest = $this->createMock( WebRequest::class );
		$handler = new GetSecurityLogContextHandler( $mockLookup );
		$handler->enableHookHandlerForTest();
		$context = [ 'existing' => 'preserved' ];
		$info = [ 'request' => $mockRequest ];

		$handler->onGetSecurityLogContext( $info, $context );

		$this->assertSame( [ 'existing' => 'preserved' ], $context );
	}
}
