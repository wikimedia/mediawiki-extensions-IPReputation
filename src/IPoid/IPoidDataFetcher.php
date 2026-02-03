<?php

namespace MediaWiki\Extension\IPReputation\IPoid;

interface IPoidDataFetcher {

	/**
	 * Helper for {@link self::getIPoidDataForIp} that does not cache the response from IPoid, and returns
	 * a value that can be returned from the callback provided to {@link WANObjectCache::getWithSetCallback}.
	 *
	 * @param string $ip
	 * @param string $caller
	 * @return array|false|null The value to be returned and also cached. `false` indicates that there was a connection
	 *   error with the backend, or the data is malformed. `null` indicates that the connection was fine, but no data
	 *   was found for the IP.
	 */
	public function getDataForIp( string $ip, string $caller ): array|false|null;

	/**
	 * Name of the backend ('opensearch', 'nodejs', 'null'), used for
	 * separating the metrics in Prometheus
	 */
	public function getBackendName(): string;

}
