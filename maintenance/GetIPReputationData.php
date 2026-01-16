<?php

namespace MediaWiki\IPReputation\Maintenance;

use MediaWiki\Extension\IPReputation\Services\IPReputationIPoidDataLookup;
use MediaWiki\Json\FormatJson;
use MediaWiki\Maintenance\Maintenance;
use Wikimedia\IPUtils;

// Light-weight maintenance script for local development (or QA testing in production), does not need testing coverage.
// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
class GetIPReputationData extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Retrieve data for an IP address.' );
		$this->addArg( 'ip', 'The IP address to use in the lookup.' );
		$this->requireExtension( 'IPReputation' );
	}

	public function execute() {
		$ip = $this->getArg( 1 );
		if ( !IPUtils::isValid( $ip ) ) {
			$this->fatalError( "\"$ip\" is not a valid IP address." );
		}
		/** @var IPReputationIPoidDataLookup $IPReputationIPoidDataLookup */
		$IPReputationIPoidDataLookup = $this->getServiceContainer()->getService( 'IPReputationIPoidDataLookup' );
		$result = $IPReputationIPoidDataLookup->getIPoidDataForIp( $ip, __METHOD__, false );
		if ( !$result ) {
			$this->output( "No result found" . PHP_EOL );
			return;
		}
		$this->output( FormatJson::encode( $result ) . PHP_EOL );
	}
}

$maintClass = GetIPReputationData::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
