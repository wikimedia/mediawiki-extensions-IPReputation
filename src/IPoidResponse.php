<?php

namespace MediaWiki\Extension\IPReputation;

use JsonSerializable;

/**
 * Value object returned by {@link IPReputationIPoidDataLookup::getIPoidDataForIp}
 */
class IPoidResponse implements JsonSerializable {

	/** @var string[]|null */
	private ?array $behaviors;

	/** @var string[] */
	private array $risks;

	/** @var string[]|null */
	private ?array $tunnelOperators;

	/** @var string[]|null */
	private ?array $proxies;

	private ?int $numUsersOnThisIP;
	private ?int $countries;

	/**
	 * @param string[]|null $behaviors
	 * @param string[] $risks
	 * @param string[]|null $tunnelOperators
	 * @param string[]|null $proxies
	 * @param int|null $numUsersOnThisIP
	 * @param int|null $countries
	 */
	private function __construct(
		?array $behaviors,
		array $risks,
		?array $tunnelOperators,
		?array $proxies,
		?int $numUsersOnThisIP,
		?int $countries
	) {
		$this->behaviors = $behaviors;
		$this->risks = $risks;
		$this->tunnelOperators = $tunnelOperators;
		$this->proxies = $proxies;
		$this->numUsersOnThisIP = $numUsersOnThisIP;
		$this->countries = $countries;
	}

	/**
	 * Convert raw data from the IPoid service into an {@link IPoidResponse} object.
	 *
	 * @param array $data IP data returned by IPInfo
	 *
	 * @return self
	 * @internal For use by {@link IPReputationIPoidDataLookup::getIPoidDataForIp} and tests
	 */
	public static function newFromArray( array $data ): IPoidResponse {
		if ( !isset( $data['risks'] ) || !$data['risks'] ) {
			// 'risks' should always be set and populated, but if not set to 'UNKNOWN'.
			$data['risks'] = [ 'UNKNOWN' ];
		}

		return new IPoidResponse(
			$data['behaviors'] ?? null,
			$data['risks'],
			$data['tunnels'] ?? null,
			$data['proxies'] ?? null,
			$data['client_count'] ?? null,
			$data['countries'] ?? null
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
