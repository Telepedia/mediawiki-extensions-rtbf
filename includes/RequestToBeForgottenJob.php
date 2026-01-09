<?php

namespace Telepedia\Extensions\RequestToBeForgotten;

use Exception;
use Job;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use WikiMap;
use Wikimedia\Rdbms\IDatabase;

class RequestToBeForgottenJob extends Job {

	/**
	 * the job name
	 */
	public const JOB_NAME = 'RequestToBeForgottenJob';

	/**
	 * The username that this user should be renamed to
	 * @var string
	 */
	private string $newUsername;

	/**
	 * The user ID associated with this user
	 * @var int
	 */
	private int $userId;

	/**
	 * The old username; used incase anything was migrated between when the request as submitted and
	 * the job runs
	 * @var string
	 */
	private string $oldUsername;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	private \Psr\Log\LoggerInterface $logger;

	/**
	 * The request ID
	 * @var int
	 */
	private int $reqId;

	public function __construct(
		array $params
	) {
		parent::__construct( 'RequestToBeForgottenJob', $params );

		$this->userId = $params['user_id'];
		$this->newUsername = $params['targetUsername'];
		$this->oldUsername = $params['originalUsername'];
		$this->reqId = $params['rq_id'];
		$this->logger = LoggerFactory::getInstance( 'RTBF' );
	}

	/**
	 * @inheritDoc
	 */
	public function run(): bool {
		// @TODO: get from DI
		// before we do anything, lets update the status in the database to start this request
		$this->logger->info("Beginning anonymisation request for $this->oldUsername");
		$services = MediaWikiServices::getInstance();

		$dbw = $services->getConnectionProvider()->getPrimaryDatabase('virtual-rtbf');

		$dbw->newUpdateQueryBuilder()
			->table( 'rtbf_request_targets')
			->set(
				[
					'status' => RTBFService::STATUS_IN_PROGRESS,
					'updated_at' => $dbw->timestamp()
				]
			)
			->where(
				[
					'request_id' => $this->reqId,
					'wiki_id' => WikiMap::getCurrentWikiId()
				]
			)
			->caller( __METHOD__ )
			->execute();

		$affected = $dbw->affectedRows();

		// if we didn't update 1 row, then something went wrong; since we will have already begun anonymisation
		if ( $affected !== 1 ) {
			$this->logger->error("Failed to update the status of the request; continuing...",
			[
				'request_id' => $this->reqId,
				'wiki_id' => WikiMap::getCurrentWikiId()

			]);
		}

		$userFactory = $services->getUserFactory();

		$user = $userFactory->newFromId( $this->userId );
		$actorId = $user->getActorId();

		$lbFactory = $services->getDBLoadBalancerFactory();
		$dbw = $lbFactory->getMainLB()->getMaintenanceConnectionRef( DB_PRIMARY );

		$deletions = [
			'block' => [
				[
					'where' => [
						'bl_by_actor' => $actorId,
					],
				],
			],
			'block_target' => [
				[
					'where' => [
						'bt_id' => $this->userId,
					],
				],
			],
			'user_groups' => [
				[
					'where' => [
						'ug_user' => $this->userId,
					],
				],
			],
			'cu_changes' => [
				[
					'where' => [
						'cuc_actor' => $actorId,
					],
				],
			],
			'cu_log' => [
				[
					'where' => [
						'cul_target_id' => $this->userId,
						'cul_type' => [ 'useredits', 'userips' ],
					],
				],
				[
					'where' => [
						'cul_actor' => $actorId,
					],
				],
			]
		];

		$tableUpdates = [
			'recentchanges' => [
				[
					'fields' => [
						'rc_ip' => '0.0.0.0',
					],
					'where' => [
						'rc_actor' => $actorId,
					],
				],
			],
			'abuse_filter_log' => [
				[
					'fields' => [
						'afl_user_text' => $this->newUsername,
					],
					'where' => [
						'afl_user_text' => $this->oldUsername,
					],
				],
			],
			'ajaxpoll_vote' => [
				[
					'fields' => [
						'poll_ip' => '0.0.0.0',
					],
					'where' => [
						'poll_actor' => $actorId,
					],
				],
			],
			'Comments' => [
				[
					'fields' => [
						'Comment_IP' => '0.0.0.0',
					],
					'where' => [
						'Comment_actor' => $actorId,
					],
				],
			],
			'echo_event' => [
				[
					'fields' => [
						'event_agent_ip' => null,
					],
					'where' => [
						'event_agent_id' => $this->userId,
					],
				],
			],
			'flow_tree_revision' => [
				[
					'fields' => [
						'tree_orig_user_ip' => null,
					],
					'where' => [
						'tree_orig_user_id' => $this->userId,
					],
				],
			],
			'flow_revision' => [
				[
					'fields' => [
						'rev_user_ip' => null,
					],
					'where' => [
						'rev_user_id' => $this->userId,
					],
				],
				[
					'fields' => [
						'rev_mod_user_ip' => null,
					],
					'where' => [
						'rev_mod_user_id' => $this->userId,
					],
				],
				[
					'fields' => [
						'rev_edit_user_ip' => null,
					],
					'where' => [
						'rev_edit_user_id' => $this->userId,
					],
				],
			],
			'moderation' => [
				[
					'fields' => [
						'mod_header_xff' => '',
						'mod_header_ua' => '',
						'mod_ip' => '0.0.0.0',
					],
					'where' => [
						'mod_user' => $this->userId,
					],
				],
				[
					'fields' => [
						'mod_header_xff' => '',
						'mod_header_ua' => '',
						'mod_ip' => '0.0.0.0',
						'mod_user_text' => $this->oldUsername,
					],
					'where' => [
						'mod_user_text' => $this->oldUsername,
					],
				],
			],
			'report_reports' => [
				[
					'fields' => [
						'report_user_text' => $this->newUsername,
					],
					'where' => [
						'report_user_text' => $this->oldUsername,
					],
				],
				[
					'fields' => [
						'report_handled_by_text' => $this->newUsername,
					],
					'where' => [
						'report_handled_by_text' => $this->oldUsername,
					],
				],
			],
			'Vote' => [
				[
					'fields' => [
						'vote_ip' => '0.0.0.0',
					],
					'where' => [
						'vote_actor' => $actorId,
					],
				],
			],
		];

		// do the deletions
		foreach ( $deletions as $key => $value ) {
			if ( $dbw->tableExists( $key, __METHOD__ ) ) {
				foreach ( $value as $fields ) {
					try {
						$method = __METHOD__;
						$dbw->doAtomicSection( $method,
							static function () use ( $dbw, $key, $fields, $method ) {
								$dbw->newDeleteQueryBuilder()
									->deleteFrom( $key )
									->where( $fields['where'] )
									->caller( $method )
									->execute();
							},
							IDatabase::ATOMIC_CANCELABLE
						);

						$lbFactory->waitForReplication();
					} catch ( Exception $e ) {
						$this->setLastError( get_class( $e ) . ': ' . $e->getMessage() );
						continue;
					}
				}
			}
		}

		// do the updates
		foreach ( $tableUpdates as $key => $value ) {
			if ( $dbw->tableExists( $key, __METHOD__ ) ) {
				foreach ( $value as $fields ) {
					try {
						$method = __METHOD__;
						$dbw->doAtomicSection( $method,
							static function () use ( $dbw, $key, $fields, $method ) {
								$dbw->newUpdateQueryBuilder()
									->update( $key )
									->set( $fields['fields'] )
									->where( $fields['where'] )
									->caller( $method )
									->execute();
							},
							IDatabase::ATOMIC_CANCELABLE
						);

						$lbFactory->waitForReplication();
					} catch ( Exception $e ) {
						$this->setLastError( get_class( $e ) . ': ' . $e->getMessage() );
						continue;
					}
				}
			}
		}

		// now we delete any pages for the user (both their old username, and their new username just in case)
		$user = User::newSystemUser( 'Telepedia', [ 'steal' => true ] );
		if ( !$user ) {
			// should never happen, but alas
			$this->setLastError( 'Cannot create system user; username is invalid' );
			return false;
		}

		$userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();

		// hide the deletions, somewhat painfully, from RC
		// this doesn't hide them full stop, but it's enough for this purpose
		$userGroupManager->addUserToGroup( $user, 'bot', null, true );

		$oldUserPageTitle = Title::makeTitle( NS_USER, $this->oldUsername );
		$newUserPageTitle = Title::makeTitle( NS_USER, $this->newUsername );

		$namespaces = [
			NS_USER,
			NS_USER_TALK,
		];

		$rows = $dbw->newSelectQueryBuilder()
			->table( 'page' )
			->fields( [
				'page_namespace',
				'page_title',
			] )
			->where( [
				"page_namespace IN (" . implode(',', $namespaces) . ")",
				"(" .
				"page_title " . $dbw->buildLike( $oldUserPageTitle->getDBkey() . '/', $dbw->anyString() ) .
				" OR page_title = " . $dbw->addQuotes( $oldUserPageTitle->getDBkey() ) .
				" OR page_title " . $dbw->buildLike( $newUserPageTitle->getDBkey() . '/', $dbw->anyString() ) .
				" OR page_title = " . $dbw->addQuotes( $newUserPageTitle->getDBkey() ) .
				")"
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$wikiPageFactory = $services->getWikiPageFactory();
		$deletePageFactory = $services->getDeletePageFactory();
		$titleFactory = $services->getTitleFactory();

		foreach ( $rows as $row ) {
			$title = $titleFactory->newFromRow( $row );
			$deletePage = $deletePageFactory->newDeletePage(
				$wikiPageFactory->newFromTitle( $title ),
				$user
			);

			$status = $deletePage->setSuppress( true )->forceImmediate( true )->deleteUnsafe( '' );
			if ( !$status->isOK() ) {
				$statusMessage = $status->getMessages( 'error' );
				$errorMessage = json_encode( $statusMessage );
				$this->setLastError( "Failed to delete user page for $this->userId. Error: $errorMessage" );
			}
		}

		// bye from archive
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'archive' )
			->where( [
				"ar_namespace IN (" . implode( ',', $namespaces ) . ")",
				"(" .
				"ar_title " . $dbw->buildLike( $oldUserPageTitle->getDBkey() . '/', $dbw->anyString() ) .
				" OR ar_title = " . $dbw->addQuotes( $oldUserPageTitle->getDBkey() ) .
				" OR ar_title " . $dbw->buildLike( $newUserPageTitle->getDBkey() . '/', $dbw->anyString() ) .
				" OR ar_title = " . $dbw->addQuotes( $newUserPageTitle->getDBkey() ) .
				")"
			] )
			->caller( __METHOD__ )
			->execute();

		// bye from logging
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'logging' )
			->where( [
				"(" .
				"log_title " . $dbw->buildLike( $oldUserPageTitle->getDBkey() . '/', $dbw->anyString() ) .
				" OR log_title = " . $dbw->addQuotes( $oldUserPageTitle->getDBkey() ) .
				" OR log_title " . $dbw->buildLike( $newUserPageTitle->getDBkey() . '/', $dbw->anyString() ) .
				" OR log_title = " . $dbw->addQuotes( $newUserPageTitle->getDBkey() ) .
				")"
			] )
			->caller( __METHOD__ )
			->execute();

		// bye from recent changes
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'recentchanges' )
			->where( [
				"(" .
				"rc_title " . $dbw->buildLike( $oldUserPageTitle->getDBkey() . '/', $dbw->anyString() ) .
				" OR rc_title = " . $dbw->addQuotes( $oldUserPageTitle->getDBkey() ) .
				" OR rc_title " . $dbw->buildLike( $newUserPageTitle->getDBkey() . '/', $dbw->anyString() ) .
				" OR rc_title = " . $dbw->addQuotes( $newUserPageTitle->getDBkey() ) .
				")"
			] )
			->caller( __METHOD__ )
			->execute();

		// now try and update the request; here we do not want MediaWiki's transaction handler to rollback the changes
		// if we couldn't update the request status - since we will have already partially deleted the users stuff and globally
		// renamed them, but we need to log the error and allow the job to finish
		try {
			$dbwRtbf = $services->getConnectionProvider()->getPrimaryDatabase('virtual-rtbf');

			$dbwRtbf->newUpdateQueryBuilder()
				->update( 'rtbf_request_targets' )
				->set( [
					'status' => RTBFService::STATUS_FINISHED,
					'updated_at' => $dbwRtbf->timestamp()
				] )
				->where( [
					'request_id' => $this->reqId,
					'wiki_id' => WikiMap::getCurrentWikiId()
				] )
				->caller( __METHOD__ )
				->execute();

			// check that all jobs have finished for this request; if so, update the master request to say so
			/** @var RTBFService $rtbfService */
			$rtbfService = $services->getService( 'RTBFService' );
			$rtbfService->checkAndFinaliseRequest( $this->reqId );
		} catch ( Exception $e ) {
			$this->logger->error(
				"RTBF Job COMPLETED successfully for user {$this->userId}, but failed to update status database.",
				[ 'exception' => $e->getMessage(), 'req_id' => $this->reqId ]
			);
			// return true even if we couldn't update the status - if not, the job will be reinserted into the queue and fail
			// again and again as the data doesn't exist
			return true;
		}
		return true;
	}
}