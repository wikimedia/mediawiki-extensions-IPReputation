<?php

namespace MediaWiki\Extension\IPReputation\IPoid;

use Psr\Log\LoggerInterface;

/**
 * @codeCoverageIgnore Not intended for use in production
 */
class NullDataFetcher implements IPoidDataFetcher {

	public function __construct(
		private readonly LoggerInterface $logger
	) {
	}

	/** @inheritDoc */
	public function getDataForIp( string $ip, string $caller ): array|false|null {
		$this->logger->debug(
			'IPReputation using NullDataFetcher for {caller}',
			[ 'caller' => $caller ]
		);
		return false;
	}

	/** @inheritDoc */
	public function getBackendName(): string {
		return 'null';
	}
}
