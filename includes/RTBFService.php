<?php

namespace Telepedia\Extensions\RequestToBeForgotten;

use Exception;
use JobSpecification;
use MailAddress;
use MediaWiki\Html\TemplateParser;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Session\SessionManager;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MWCryptRand;
use Random\RandomException;
use RuntimeException;
use StatusValue;
use Telepedia\Extensions\RequestToBeForgotten\Hooks\RightToBeForgottenHookRunner;
use Telepedia\Extensions\UAM\GlobalUserService;
use Telepedia\UserProfileV2\Avatar\UserProfileV2AvatarBackend;
use UserMailer;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\Platform\ISQLPlatform;

class RTBFService {

	/**
	 * The request has been submitted and is awaiting confirmation
	 */
	public const STATUS_PENDING = 1;

	/**
	 * The user has confirmed their request and it is waiting to be picked up
	 */
	public const STATUS_CONFIRMED_WAITING = 2;

	/**
	 * Request has been picked up and is in process
	 */
	public const STATUS_IN_PROGRESS = 3;

	/**
	 * Request has finished and the user has been anonymised et al.
	 */
	public const STATUS_FINISHED = 4;

	/**
	 * This request failed
	 */
	public const STATUS_FAILED = 5;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	private \Psr\Log\LoggerInterface $logger;

	public function __construct(
		private readonly UserFactory $userFactory,
		private readonly IConnectionProvider $connectionProvider,
		private readonly GlobalUserService $globalUserService,
		private readonly JobQueueGroupFactory $jobQueueFactory,
		private readonly RightToBeForgottenHookRunner $hookRunner
	) {
		$this->logger = LoggerFactory::getInstance( 'RTBF' );
	}

	/**
	 * The user initiated a request to be forgotten from Special:RequestToBeForgotten. This sends a
	 * confirmation email
	 * @param UserIdentity $user
	 *
	 * @return StatusValue
	 * @throws RandomException
	 */
	public function initiateUserRequest( UserIdentity $user ): StatusValue {
		// check if they already have a pending request, if so, we can only have one request
		if ( $this->hasPendingRequest( $user->getId() ) ) {
			return StatusValue::newFatal( 'rtbf-already-pending' );
		}

		$token = $this->generateUUID();
		$targetUsername = 'Anonymous ' . substr( $token, 0, 8 );

		$reqId = $this->insertRequestRow(
			$user->getId(),
			$user->getName(),
			self::STATUS_PENDING,
			$token,
			$targetUsername,
			'web'
		);

		if ( !$reqId ) {
			return StatusValue::newFatal( 'rtbf-db-error' );
		}

		try {
			$this->sendConfirmationEmail( $reqId, $user );
		} catch ( Exception $e ) {
			$this->logger->error(
				"Failed to send confirmation email for RTBF $reqId",
				[ 'error' => $e->getMessage() ]
			);
			return StatusValue::newFatal( 'rtbf-email-error' );
		}

		return StatusValue::newGood();
	}

	/**
	 * Confirm the token, and execute the anonymisation for this user
	 * @param string $token
	 * @param UserIdentity $performer the user performing the action
	 * @return StatusValue
	 */
	public function confirmAndExecute( string $token, UserIdentity $performer ): StatusValue {
		$request = $this->getRequestByToken( $token );

		// request either does not exist, or is not pending
		if ( !$request || $request->status !== self::STATUS_PENDING ) {
			return StatusValue::newFatal( 'rtbf-invalid-token' );
		}

		if ( wfTimestampNow() > $request->tokenExpiration ) {
			return StatusValue::newFatal( 'rtbf-expired-token' );
		}

		// check that the user confirming this token is the one that requested it.
		// caller is responsible for validating the users token
		if ( $performer->getId() !== $request->userId ) {
			return StatusValue::newFatal( 'rtbf-user-mismatch' );
		}

		$this->updateStatus( $request->id, self::STATUS_IN_PROGRESS );

		// just return here, whether its an error or success - note here we do not update
		// the status to finished, since we will be inserting a job for every wiki, the last job
		// to run will check the queue, if all of the jobs have finished, we mark it as completed
		// this happens async
		return $this->anonymiseUser( $request, $request->targetUsername );
	}

	/**
	 * Force the anonymisation of a user without first sending and waiting for a email confirmation
	 * @param int $userId
	 *
	 * @return StatusValue
	 * @throws RandomException
	 */
	public function forceFromCLI( int $userId ): StatusValue {
		// @FIXME: check that this request came from the CLI, otherwise, bail
		$user = $this->userFactory->newFromId( $userId );

		if ( !$user->isRegistered() ) {
			return StatusValue::newFatal( 'rtbf-user-not-found' );
		}

		$token = $this->generateUUID();
		$targetUsername = 'Anonymous ' . substr( $token, 0, 8 );

		$reqId = $this->insertRequestRow(
			$userId,
			$user->getName(),
			self::STATUS_IN_PROGRESS,
			$token,
			$targetUsername,
			'cli'
		);

		// manually create the request here to pass to anonymiseUser() instead of loading from DB since will
		// all happen at once
		$rtbfRequest = $this->loadFromId( $reqId );

		return $this->anonymiseUser( $rtbfRequest, $targetUsername );
	}

	/**
	 * Generate a simple random UUIDv4; a makeshift implementation of
	 * Ramsey\Uuid without the dependency :P
	 *
	 * @return string
	 * @throws RandomException
	 */
	private function generateUUID(): string {
		$data = random_bytes( 16 );
		$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 );
		$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}

	/**
	 * Insert a request to be anonymised into the queue
	 * @param int $userId user being anonmyised
	 * @param string $originalUsername the username of the user at the point the request was made
	 * @param int $status the current status
	 * @param string $token the UUID token
	 * @param string $targetName target username to rename them to
	 * @param string $source 'web' or 'cli' for tracking purposes
	 *
	 * @return int|null ID of the inserted row
	 */
	private function insertRequestRow(
		int $userId,
		string $originalUsername,
		int $status,
		string $token,
		string $targetName,
		string $source = 'web'
	): ?int {
		$dbw = $this->connectionProvider->getPrimaryDatabase( 'virtual-rtbf' );

		$now = $dbw->timestamp();
		// token valid for 15 minutes, at which point it is invalidated and cannot be used for security
		// this effecively cancels the request after this time if the token is not used
		$expires = $dbw->timestamp( time() + 900 );

		$row = [
			'rq_user_id' => $userId,
			'rq_user_name_original' => $originalUsername,
			'rq_user_name_target' => $targetName,
			'rq_status' => $status,
			'rq_source' => $source,
			'rq_token' => $token,
			'rq_token_expires' => $expires,
			'rq_created_at' => $now,
		];

		$dbw->newInsertQueryBuilder()
			->insertInto( 'rtbf_queue' )
			->row( $row )
			->caller( __METHOD__ )
			->execute();

		return $dbw->insertId();
	}
	/**
	 * Update the status in the database for this request
	 * @param int $reqId the ID for the request
	 * @param int $status the status (one of the constants from the service)
	 *
	 * @return void
	 */
	private function updateStatus( int $reqId, int $status ): void {
		$dbw = $this->connectionProvider->getPrimaryDatabase( 'virtual-rtbf' );
		$dbw->newUpdateQueryBuilder()
			->update( 'rtbf_queue' )
			->set(
				[
					'rq_status' => $status
				]
			)
			->where(
				[
					'rq_id' => $reqId
				]
			)
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Does this user have a current request in progress?
	 * @param int $userId
	 *
	 * @return bool
	 */
	public function hasPendingRequest( int $userId ): bool {
		// statuses that we consider to be active/in progress
		$activeStatuses = [
			self::STATUS_PENDING,
			self::STATUS_CONFIRMED_WAITING,
			self::STATUS_IN_PROGRESS
		];

		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-rtbf' );
		$now = $dbr->timestamp();

		// only check requests that have not expired
		// we will run a cron job eventually which prunes old requests that have not begun
		$count = $dbr->newSelectQueryBuilder()
			->select( '1' )
			->from( 'rtbf_queue' )
			->where( [
				'rq_user_id' => $userId,
				'rq_status' => $activeStatuses,
				'rq_token_expires > ' . $now
			] )
			->caller( __METHOD__ )
			->fetchField();

		return ( bool )$count;
	}

	/**
	 * Get a pending request by its token
	 * @param string $token
	 * @return RTBFRequest|null
	 */
	private function getRequestByToken( string $token ): ?RTBFRequest {
		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-rtbf' );

		$row = $dbr->newSelectQueryBuilder()
			->select( ISQLPlatform::ALL_ROWS )
			->from( 'rtbf_queue' )
			->where(
				[
				'rq_token' => $token
				]
			)
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$row ) {
			return null;
		}

		return new RTBFRequest(
			( int )$row->rq_id,
			( int )$row->rq_user_id,
			$row->rq_user_name_original,
			( int )$row->rq_status,
			$row->rq_token,
			$row->rq_user_name_target,
			$row->rq_token_expires,
			$row->rq_created_at,
			$row->rq_completed_at
		);
	}

	private function anonymiseUser( RTBFRequest $rtbfRequest, string $targetUsername ): StatusValue {
		// check again that the user ID we passed resulted in an actual user
		$user = $this->userFactory->newFromId( $rtbfRequest->userId );
		if ( !$user->isRegistered() ) {
			return StatusValue::newFatal( 'rtbf-user-not-found' );
		}

		$this->logger->info(
			"Starting RTBF for $rtbfRequest->originalUsername (ID: $rtbfRequest->userId) to $rtbfRequest->targetUsername",
		);

		// Remove all of the PII from the users global account (email, password etc) and globally rename them
		$globalStatus = $this->performGlobalRenaming( $user, $rtbfRequest );

		if ( !$globalStatus->isGood() ) {
			return $globalStatus;
		}

		$wikis = $this->globalUserService->getAttachedWikis( $rtbfRequest->userId );
		if ( empty( $wikis ) ) {
			// no wikis to work on, so just complete the request
			$this->markComplete( $rtbfRequest->id );
			return StatusValue::newGood();
		}

		$dbw = $this->connectionProvider->getPrimaryDatabase( 'virtual-rtbf' );
		$rows = [];

		// don't care about the edits
		foreach ( $wikis as $wiki => $_ ) {
			$rows[] = [
				'request_id' => $rtbfRequest->id,
				'wiki_id' => $wiki,
				'status' => self::STATUS_PENDING,
				'updated_at' => $dbw->timestamp(),
			];
		}

		$dbw->newInsertQueryBuilder()
			->insertInto( 'rtbf_request_targets' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();

		foreach ( $wikis as $wiki => $_ ) {
			$jobQueueGroup = $this->jobQueueFactory->makeJobQueueGroup( $wiki );

			$jobQueueGroup->push(
				new JobSpecification(
					RequestToBeForgottenJob::JOB_NAME,
					[
						'rq_id' => $rtbfRequest->id,
						'user_id' => $rtbfRequest->userId,
						'originalUsername' => $rtbfRequest->originalUsername,
						'targetUsername' => $rtbfRequest->targetUsername,
					]
				)
			);
		}
		return StatusValue::newGood();
	}

	private function performGlobalRenaming( User $user, RTBFRequest $rtbfRequest ): StatusValue {
		// invalidate the cache immediately for this user
		$user->invalidateCache();

		$randomPassword = MWCryptRand::generateHex( 32 );
		$status = $user->changeAuthenticationData( [
			'username' => $user->getName(),
			'password' => $randomPassword,
			'retype' => $randomPassword,
		] );

		if ( !$status->isGood() ) {
			return $status;
		}

		$user->setRealName( '' );
		if ( $user->getEmail() ) {
			$user->invalidateEmail();
		}

		// here we save the above changes before we do the SQL changes for the username
		$user->saveSettings();

		// now we use SQL to change the username as we need to do the actor table too
		// basically a copy of RenameUserSQL::class
		$dbw = $this->connectionProvider->getPrimaryDatabase();

		try {
			$dbw->startAtomic( __METHOD__ );

			// user table
			$this->logger->debug(
				"Renaming user in user table for $rtbfRequest->originalUsername (ID: $rtbfRequest->userId)" .
				" to $rtbfRequest->targetUsername",
			);
			$dbw->newUpdateQueryBuilder()
				->update( 'user' )
				->set( [
					'user_name' => $rtbfRequest->targetUsername,
					'user_touched' => $dbw->timestamp()
				] )
				->where( [
					'user_id' => $user->getId(),
					'user_name' => $rtbfRequest->originalUsername
				] )
				->caller( __METHOD__ )
				->execute();

			// Actor table
			$this->logger->debug(
				"Renaming user in actor table for $rtbfRequest->originalUsername (ID: $rtbfRequest->userId)" .
				" to $rtbfRequest->targetUsername",
			);

			$dbw->newUpdateQueryBuilder()
				->update( 'actor' )
				->set( [ 'actor_name' => $rtbfRequest->targetUsername ] )
				->where( [
					'actor_user' => $user->getId(),
					'actor_name' => $rtbfRequest->targetUsername
				] )
				->caller( __METHOD__ )
				->execute();

			$dbw->endAtomic( __METHOD__ );
		} catch ( Exception $e ) {
			$this->logger->error( "RTBF rename failed: " . $e->getMessage() );
			return StatusValue::newFatal( 'rtbf-rename-fail' );
		}

		// general cleanup; get a fresh user object, and invalidate any sessions
		$freshUser = $this->userFactory->newFromId( $rtbfRequest->userId );
		SessionManager::singleton()->invalidateSessionsForUser( $freshUser );

		// delete the users avatar
		// @TODO: remove any masthead stuff; pending rewrite for UPv2
		$backend = new UserProfileV2AvatarBackend( 'upv2avatars' );

		$extensions = [ 'png', 'gif', 'jpg', 'jpeg', 'webp' ];

		foreach ( $extensions as $ext ) {
			if ( $backend->fileExists( 'avatar' . '_', $rtbfRequest->userId, $ext ) ) {
				$backend->getFileBackend()->quickDelete( [
					'src' => $backend->getPath( 'avatar' . '_', $rtbfRequest->userId, $ext )
				] );
			}
		}
		return StatusValue::newGood();
	}

	/**
	 * Mark this request as completed
	 * @param int $reqId
	 * @return void
	 */
	private function markComplete( int $reqId ): void {
		$dbw = $this->connectionProvider->getPrimaryDatabase( 'virtual-rtbf' );
		$dbw->newUpdateQueryBuilder()
			->update( 'rtbf_queue' )
			->set(
				[
					'rq_status' => self::STATUS_FINISHED,
					'rq_completed_at' => $dbw->timestamp()
				]
			)
			->where(
				[
					'rq_id' => $reqId
				]
			)
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Send the user a confirmation email to ensure that this is really what they want to do
	 * @param RTBFRequest $rtbfRequest
	 * @param User $user
	 *
	 * @return void
	 */
	private function sendConfirmationEmail( int $reqId, UserIdentity $user ): void {
		$rtbfRequest = $this->loadFromId( $reqId );

		if ( !$rtbfRequest ) {
			throw new RuntimeException( "Request does not exist." );
		}

		$url = Title::makeTitle( NS_SPECIAL, "RequestToBeForgotten/$rtbfRequest->confirmationToken" )
			->getCanonicalURL();

		$data = [
			'rtbf-email-title' => wfMessage( 'rtbf-email-title' )->parse(),
			'rtbf-body-intro' => wfMessage( 'rtbf-email-body-intro', $rtbfRequest->originalUsername )->parse(),
			'rtbf-body-text' => wfMessage( 'rtbf-email-body-text' )->parse(),
			'rtbf-body-text2' => wfMessage( 'rtbf-email-body-text-2' )->parse(),
			'rtbf-footer-text' => wfMessage( 'rtbf-footer-text' )->plain(),
			'rtbf-confirmation-url' => $url
		];

		$nonHTML = wfMessage( 'rtbf-email-body-text-plain', $rtbfRequest->originalUsername, $url )->text();

		$templateParser = new TemplateParser( __DIR__ . '/../templates' );

		$html = $templateParser->processTemplate(
			'rtbf_confirmation',
			$data
		);

		$to = MailAddress::newFromUser( $user );

		global $wgPasswordSender;

		$from = new MailAddress(
			$wgPasswordSender,
			'Telepedia'
		);

		$body = [
			'text' => $nonHTML,
			'html' => $html
		];


		// try and send, we will try/catch this so any exception will bubble up
		UserMailer::send(
			$to,
			$from,
			'Confirm your request',
			$body
		);
	}

	/**
	 * Load a request from the database by its ID
	 *
	 * @param int $reqId
	 *
	 * @return RTBFRequest|null
	 */
	public function loadFromId( int $reqId ): ?RTBFRequest {
		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-rtbf' );

		$res = $dbr->newSelectQueryBuilder()
			->select( ISQLPlatform::ALL_ROWS )
			->from( 'rtbf_queue' )
			->where( [ 'rq_id' => $reqId ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$res ) {
			return null;
		}

		return new RTBFRequest(
			$res->rq_id,
			$res->rq_user_id,
			$res->rq_user_name_original,
			$res->rq_status,
			$res->rq_token,
			$res->rq_user_name_target,
			$res->rq_token_expires,
			$res->rq_created_at,
			$res->rq_completed_at,
		);
	}

	public function loadAllRequests(): array {
		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-rtbf' );

		$requests = [];

		$res = $dbr->newSelectQueryBuilder()
			->select( [
				'rq_id',
				'rq_user_name_original',
				'rq_user_name_target',
				'rq_source',
				'rq_status',
				'rq_created_at'
			] )
			->from( 'rtbf_queue' )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			$requests[] = [
				'rq_id' => $row->rq_id,
				'rq_user_name_original' => $row->rq_user_name_original,
				'rq_user_name_target' => $row->rq_user_name_target,
				'rq_source' => $row->rq_source,
				'rq_status' => $row->rq_status,
				'rq_created_at' => $row->rq_created_at
			];
		}

		return $requests;
	}

	/**
	 * Return each of the jobs for the individual wikis attached to this RTBF request
	 * including their status, and the wiki
	 * @param int $reqId
	 *
	 * @return array
	 */
	public function loadWikisForRequest( int $reqId ): ?array {
		$wikis = [];

		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-rtbf' );
		$res = $dbr->newSelectQueryBuilder()
			->select( [
				'wiki_id',
				'status',
				'error_message',
				'updated_at'
			] )
			->from( 'rtbf_request_targets' )
			->where( [ 'request_id' => $reqId ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		// if we have no rows, return null; the caller must handle this situation
		if ( $res->numRows() === 0 ) {
			return null;
		}

		foreach ( $res as $row ) {
			$wikis[] = [
				'wiki_id' => $row->wiki_id,
				'status' => $row->status,
				'error_message' => $row->error_message ?? null,
				'updated_at' => $row->updated_at
			];
		}

		return $wikis;
	}

	/**
	 * Return human-readable attributes associated with a request.
	 *
	 * @param int $status the status of the request
	 *
	 * @return array
	 */
	public function getStatusAttributes( int $status ): array {
		return match ( $status ) {
			self::STATUS_PENDING => [
				'type' => 'notice',
				'text' => wfMessage( 'rtbf-status-pending' )->text()
			],
			self::STATUS_CONFIRMED_WAITING => [
				'type' => 'warning',
				'text' => wfMessage( 'rtbf-status-confirmed' )->text()
			],
			self::STATUS_IN_PROGRESS => [
				'type' => 'warning',
				'text' => wfMessage( 'rtbf-status-in-progress' )->text()
			],
			self::STATUS_FINISHED => [
				'type' => 'success',
				'text' => wfMessage( 'rtbf-status-complete' )->text()
			],
			default => [
				'type' => 'error',
				'text' => 'Unknown'
			],
		};
	}

	/**
	 * Render a CdxIconChip using the attributes for the request
	 * @param int $status the status of the request/job
	 * @return string the HTML representation of the info chip
	 */
	public function renderStatusChip( int $status ): string {
		$attr = $this->getStatusAttributes( $status );

		return sprintf(
			'<div class="cdx-info-chip cdx-info-chip--%s">
             <span class="cdx-info-chip__icon"></span>
             <span class="cdx-info-chip__text">%s</span>
          </div>',
			htmlspecialchars( $attr['type'] ),
			htmlspecialchars( $attr['text'] )
		);
	}

	/**
	 * Check if a request is finished. A request is considered to be finished if there are no more wikis to anonymise a
	 * particular user on
	 * @param int $reqId
	 * @return void
	 */
	public function checkAndFinaliseRequest( int $reqId ): void {
		$dbw = $this->connectionProvider->getPrimaryDatabase( 'virtual-rtbf' );

		$dbw->startAtomic( __METHOD__ );

		// Check if there are any rows for this request that are NOT finished/failed
		// We look for anything that is PENDING (1), WAITING (2), or IN_PROGRESS (3)
		$pendingCount = $dbw->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'rtbf_request_targets' )
			->where( [
				'request_id' => $reqId,
				'status' => [
					self::STATUS_PENDING,
					self::STATUS_CONFIRMED_WAITING,
					self::STATUS_IN_PROGRESS
				]
			] )
			->caller( __METHOD__ )
			->fetchField();

		// count is 0, all jobs are done. Update the master record.
		if ( $pendingCount == 0 ) {
			$this->logger->info( "All wiki jobs finished for Req ID $reqId. Marking master as finished." );

			$this->markComplete( $reqId );

			// run a hook to notify anything listening for completed requests
			// @TODO: move to domain events when we're on a high enough version as we don't need a hook - notif only
			$this->hookRunner->onRightToBeForgottenComplete(
				$this->loadFromId( $reqId )
			);
		}

		$dbw->endAtomic( __METHOD__ );
	}
}