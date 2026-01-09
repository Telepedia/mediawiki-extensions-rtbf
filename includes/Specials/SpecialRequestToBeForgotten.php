<?php

namespace Telepedia\Extensions\RequestToBeForgotten\Specials;

use HTMLForm;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\UnlistedSpecialPage;
use MediaWiki\User\UserFactory;
use PermissionsError;
use ReadOnlyError;
use Telepedia\Extensions\RequestToBeForgotten\RTBFService;
use UserNotLoggedIn;

class SpecialRequestToBeForgotten extends UnlistedSpecialPage {

	public function __construct(
		private readonly UserFactory $userFactory,
		private readonly RTBFService $rtbfService
	) {
		parent::__construct( 'RequestToBeForgotten', 'editmyprivateinfo' );
	}

	/**
	 * Display either the form to request anonymisation, or if the user got here from an email
	 * check their confirmation code and then kick off the job to anonymise the user
	 *
	 * @throws PermissionsError
	 * @throws ReadOnlyError
	 * @throws UserNotLoggedIn
	 */
	public function execute( $confirmation ): void {
		$this->setHeaders();
		$this->checkReadOnly();
		$this->checkPermissions();

		// we will show the email address that the confirmation will be sent to on this
		// page, so lets also require that the user has the required permission to view the email
		if ( !$this->getAuthority()->isAllowed( 'viewmyprivateinfo' ) ) {
			throw new PermissionsError( 'viewmyprivateinfo' );
		}

		// most of the below is adapted or inspired by the ConfirmEmail special page
		// @link: https://github.com/wikimedia/mediawiki/blob/41b60ab4829f04ac3afd991f175a5d183bf42deb/includes/Specials/SpecialConfirmEmail.php#L38
		if ( empty( $confirmation ) ) {
			$this->requireNamedUser();
			if ( $this->getUser()->isEmailConfirmed() ) {
				$this->showRequestForm();
			} else {
				$this->getOutput()->addWikiMsg( 'rtbf-email-not-confirmed' );
			}
			return;
		}

		// Sanity check
		$this->requireNamedUser();

		// this is a state changing operation, therefore only allow POST requests to initiate the anonymisation
		// this prevents mail scanners from clicking the link and initiating a request
		if ( $this->getRequest()->wasPosted() ) {
			// this is deprecated but the replacement is spinning my head rn so just use it ig
			if ( !$this->getUser()->matchEditToken( $this->getRequest()->getVal( 'wpEditToken' ) ) ) {
				$this->getOutput()->addWikiMsg( 'sessionfailure' );
				return;
			}

			$res = $this->rtbfService->confirmAndExecute( $confirmation, $this->getUser() );

			if ( !$res->isOK() ) {
				$this->getOutput()->addHTML(
					Html::errorBox( $this->msg( $res->getMessages( 'error' )[0]->getKey() ) )
				);
				return;
			}

			$this->getOutput()->addHTML(
				Html::successBox( $this->msg( 'rtbf-request-confirmed' )->text() )
			);

		} else {
			// this is a GET request, ask the user to confirm their intentions
			$this->showConfirmationLandingPage( $confirmation );
		}
	}

	/**
	 * Show the form to request account anonymisation
	 * @return void [Outputs to screen]
	 */
	private function showRequestForm(): void {
		$user = $this->getUser();
		$html = "";

		$html .= Html::rawElement(
			'div',
			[
				'class' => 'mw-ext-rtbf-info'
			],
			wfMessage('rtbf-request-form-info-box', $user->getName(), $user->getEmail() )->parse()
		);

		$formFields = [
			'confirm' => [
				'type' => 'check',
				'label-message' => 'rtbf-confirm'
			]
		];

		$this->getOutput()->addHTML( $html );

		$htmlForm = HTMLForm::factory( 'codex', $formFields, $this->getContext() );
		$htmlForm
			->setSubmitText( 'Submit Request' )
			->setSubmitCallback( [ $this, 'trySubmit' ] )
			->show();
	}

	public function trySubmit( array $params ) {
		$res = $this->rtbfService->initiateUserRequest(
			$this->getUser()
		);

		if ( !$res->isOK() ) {
			$this->getOutput()->addHTML(
				Html::errorBox(
					$this->msg( $res->getMessages( 'error' )[0]->getKey() )
				)
			);
			return false;
		}

		$this->getOutput()->addHTML(
			Html::warningBox(
				'Your request has been received. We’ve sent a message to the email associated with your account. Please confirm it within 15 minutes.'
			)
		);
	}

	/**
	 * Helper function to render a confirmation screen for the user to confirm their intentions
	 * @param string $token
	 *
	 * @return void
	 */
	private function showConfirmationLandingPage( string $token ): void {
		$this->getOutput()->setPageTitleMsg( $this->msg( 'rtbf-confirm-title' ) );

		$this->getOutput()->addWikiMsg( 'rtbf-confirm-intro-text' );

		$form = Html::openElement( 'form', [
			'method' => 'post',
			'action' => $this->getPageTitle( $token )->getLocalURL(),
			'class' => 'mw-rtbf-confirm-form'
		] );

		// We don't strictly need a hidden field for token if it's in the action URL,
		// but it doesn't hurt.
		$form .= Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() );

		$form .= Html::submitButton(
			$this->msg( 'rtbf-confirm-title' )->text(),
			[ 'class' => 'cdx-button cdx-button--action-progressive' ]
		);

		$form .= Html::closeElement( 'form' );

		$this->getOutput()->addHTML( $form );
	}

	/**
	 * @inheritDoc
	 */
	public function doesWrites(): bool {
		return true;
	}
}