<?php

namespace MediaWiki\Extension\IPReputation\IPoid;

use JsonSerializable;

/**
 * Value object returned by {@link IPReputationIPoidDataLookup::getIPoidDataForIp}
 */
class IPoidResponse implements JsonSerializable {

	/**
	 * @param string[] $risks
	 * @param string[]|null $behaviors
	 * @param string[]|null $tunnelOperators
	 * @param string[]|null $proxies
	 * @param int|null $numUsersOnThisIP
	 * @param int|null $countries
	 */
	private function __construct(
		private array $risks,
		private ?array $behaviors = null,
		private ?array $tunnelOperators = null,
		private ?array $proxies = null,
		private ?int $numUsersOnThisIP = null,
		private ?int $countries = null,
	) {
	}

	/**
	 * Convert raw data from the IPoid service into an {@link IPoidResponse} object.
	 *
	 * @param array $data IP data returned by {@link IPReputationIPoidDataLookup::getIPoidDataForIp}
	 *
	 * @return self
	 * @internal For use by {@link IPReputationIPoidDataLookup::getIPoidDataForIp} and tests
	 */
	public static function newFromArray( array $data ): IPoidResponse {
		return new self(
			risks: $data['risks'] ?? [ 'UNKNOWN' ],
			behaviors: $data['behaviors'] ?? null,
			tunnelOperators: $data['tunnels'] ?? null,
			proxies: $data['proxies'] ?? null,
			numUsersOnThisIP: $data['client_count'] ?? null,
			countries: $data['countries'] ?? null,
		);
	}

	/**
	 * @return string[]|null
	 */
	public function getBehaviors(): ?array {
		return $this->behaviors;
	}

	/**
	 * @return string[]
	 */
	public function getRisks(): array {
		return $this->risks;
	}

	/**
	 * @return string[]|null
	 */
	public function getTunnelOperators(): ?array {
		return $this->tunnelOperators;
	}

	/**
	 * @return string[]|null
	 */
	public function getProxies(): ?array {
		return $this->proxies;
	}

	public function getNumUsersOnThisIP(): ?int {
		return $this->numUsersOnThisIP;
	}

	public function getCountries(): ?int {
		return $this->countries;
	}

	public function jsonSerialize(): array {
		return [
			'behaviors' => $this->getBehaviors(),
			'risks' => $this->getRisks(),
			'tunnelOperators' => $this->getTunnelOperators(),
			'proxies' => $this->getProxies(),
			'numUsersOnThisIP' => $this->getNumUsersOnThisIP(),
			'countries' => $this->getCountries(),
		];
	}
}
