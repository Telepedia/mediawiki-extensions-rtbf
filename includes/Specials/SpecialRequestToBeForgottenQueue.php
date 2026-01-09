<?php

namespace Telepedia\Extensions\RequestToBeForgotten\Specials;

use MediaWiki\Html\Html;
use MediaWiki\Html\TemplateParser;
use MediaWiki\SpecialPage\UnlistedSpecialPage;
use MediaWiki\Title\Title;
use Telepedia\Extensions\RequestToBeForgotten\RTBFService;
use Wikimedia\Timestamp\ConvertibleTimestamp;

class SpecialRequestToBeForgottenQueue extends UnlistedSpecialPage {

	public function __construct(
		private readonly RTBFService $rtbfService
	) {
		parent::__construct( 'RequestToBeForgottenQueue', 'request-to-be-forgotten-admin' );
	}

	public function execute( $id ) {
		$this->setHeaders();
		$this->checkReadOnly();
		$this->checkPermissions();

		$this->getOutput()->addModuleStyles( ['ext.rtbf.styles'] );

		if ( $id ) {
			$this->showIndividualRequest( $id );
		} else {
			$this->showAllRequests();
		}
	}

	/**
	 * Show an individual request and its status on child wikis this account is attached to
	 * @param int $id
	 *
	 * @return void [Output to screen]
	 */
	private function showIndividualRequest( int $id ): void {
		$request = $this->rtbfService->loadFromId( $id );

		// bail early if the request was not valid
		if ( !$request ) {
			$errorMsg = Html::errorBox(
				$this->msg('rtbf-request-error')->text()
			);
			$this->getOutput()->addHTML( $errorMsg );
			return;
		}

		$wikis = $this->rtbfService->loadWikisForRequest( $id );

		// The request was valid, but for some reason, the number of wikis to work on was not populated
		if ( !$wikis ) {
			$errorMsg = Html::errorBox(
				$this->msg('rtbf-request-error-wikis')->text()
			);
			$this->getOutput()->addHTML( $errorMsg );
			return;
		}

		$attributes = $this->rtbfService->getStatusAttributes( $request->status );

		$templateParser = new TemplateParser( __DIR__ . '/../../templates' );

		$req = [
			'status' => $attributes['text'],
			'originalUsername' => $request->originalUsername,
			'targetUsername' => $request->targetUsername,
			'requestedAt' => ConvertibleTimestamp::convert( TS_RFC2822, $request->createdAt )
		];

		$formattedWikis = array_map( function ( $item ) {
			$item['updated_at'] = ConvertibleTimestamp::convert( TS_RFC2822, $item['updated_at'] );
			// Delegation to Service
			$item['status'] = $this->rtbfService->renderStatusChip( $item['status'] );
			return $item;
		}, $wikis );

		$data = [
			'title' => "Wiki statuses for anonymisation of $request->originalUsername",
			'jobs' => $formattedWikis,
			'req' => $req,
			'statusNotice' => $this->rtbfService->renderStatusChip( $request->status )
		];

		$html = $templateParser->processTemplate(
			'rtbf_queue_individual',
			$data,
		);

		$this->getOutput()->addHTML( $html );
	}
	/**
	 * Show all the requests that are pending etc
	 * @return void [Output to screen]
	 */
	private function showAllRequests(): void {
		$allRequests = $this->rtbfService->loadAllRequests();

		$formattedRequests = array_map( function ( $item ) {
			// make the timestamp human-readable
			$item['rq_created_at'] = ConvertibleTimestamp::convert( TS_RFC2822, $item['rq_created_at'] );

			// link to each individual request son we can see what went wrong if something fails
			$id = $item['rq_id'];
			$item['rq_link'] = Title::newFromText( "RequestToBeForgottenQueue/$id", NS_SPECIAL )->getCanonicalURL();

			$item['rq_status'] = $this->rtbfService->renderStatusChip( $item['rq_status'] );

			return $item;
		}, $allRequests );

		$templateParser = new TemplateParser( __DIR__ . '/../../templates' );

		$data = [
			'title' => 'All Requests to be Forgotten',
			'requests' => $formattedRequests,
		];

		$html = $templateParser->processTemplate(
			'rtbf_queue',
			$data,
		);

		$this->getOutput()->addHTML( $html );
	}
}