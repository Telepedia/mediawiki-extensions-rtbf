<?php

namespace Telepedia\Extensions\RequestToBeForgotten\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Maintenance\MaintenanceFatalError;
use Telepedia\Extensions\RequestToBeForgotten\RTBFService;

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class ForgetUser extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Forcefully forgets a user, deleting their information and anonymising their account. This skips'
			. ' asking the user for confirmation.'
		);

		$this->addArg( 'id', 'The ID of the user we are requesting to forget' );

		$this->requireExtension( 'RequestToBeForgotten' );
	}

	/**
	 * @inheritDoc
	 * @throws MaintenanceFatalError
	 */
	public function execute(): void {
		$userId = $this->getArg( 'id' );
		if ( !$userId ) {
			$this->fatalError( "ID must be provided..." );
		}

		/** @var RTBFService $rtbfService */
		$rtbfService = $this->getServiceContainer()->get( 'RTBFService' );

		$res = $rtbfService->forceFromCLI( $userId );

		if ( !$res->isOK() ) {
			$this->fatalError( $res );
		}

		$this->output( "Successfully began request to anonymise user..." );
	}
}

$maintClass = ForgetUser::class;
require_once RUN_MAINTENANCE_IF_MAIN;
