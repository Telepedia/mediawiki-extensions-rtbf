<?php

namespace Telepedia\Extensions\RequestToBeForgotten\Maintenance;

use JobSpecification;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Maintenance\MaintenanceFatalError;
use Telepedia\Extensions\RequestToBeForgotten\RequestToBeForgottenJob;
use Telepedia\Extensions\RequestToBeForgotten\RTBFService;

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class FixStuckJobs extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Fixes any jobs that are stuck by reinserting them into the jobqueue'
		);

		$this->addArg( 'id', 'The ID of the request we are fixing the jobs for' );

		$this->requireExtension( 'RequestToBeForgotten' );
	}

	/**
	 * @inheritDoc
	 * @throws MaintenanceFatalError
	 */
	public function execute(): void {
		$id = $this->getArg( 'id' );

		if ( !$id ) {
			$this->fatalError( "ID must be provided..." );
		}

		/** @var RTBFService $rtbfService */
		$rtbfService = $this->getServiceContainer()->get( 'RTBFService' );

		$request = $rtbfService->loadFromId( $id );

		if ( is_null( $request ) ) {
			$this->fatalError( "Request not found..." );
		}

		$wikis = $rtbfService->loadWikisForRequest( $id );

		if ( empty( $wikis ) || is_null( $wikis ) ) {
			$this->fatalError( "No wikis found for this user, therefore no work to be done..." );
		}

		$reinsertionWikis = [];

		// get all of the wikis that we should've had a job for
		foreach ( $wikis as $wiki ) {
			$reinsertionWikis[] = $wiki['wiki_id'];
		}

		$jobQueueFactory = $this->getServiceContainer()->getJobQueueGroupFactory();

		foreach ( $reinsertionWikis as $wiki ) {
			$jobQueueGroup = $jobQueueFactory->makeJobQueueGroup( $wiki );

			$jobQueueGroup->push(
				new JobSpecification(
					RequestToBeForgottenJob::JOB_NAME,
					[
						'rq_id' => $request->id,
						'user_id' => $request->userId,
						'originalUsername' => $request->originalUsername,
						'targetUsername' => $request->targetUsername,
					]
				)
			);
		}

		$this->output( "Successfully reinserted the jobs...." );
	}
}

$maintClass = FixStuckJobs::class;
require_once RUN_MAINTENANCE_IF_MAIN;
