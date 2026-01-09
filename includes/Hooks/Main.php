<?php

namespace Telepedia\Extensions\RequestToBeForgotten\Hooks;

use MediaWiki\Html\Html;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\SpecialPage\SpecialPage;

class Main implements GetPreferencesHook {

	/**
	 * @inheritDoc
	 */
	public function onGetPreferences( $user, &$preferences ) {
		$preferences['rtbf'] = [
			'type' => 'info',
			'label-message' => 'rbtf-label',
			'raw' => true,
			'default' => Html::element(
				'a',
				[
					'href' => SpecialPage::getTitleFor( 'RequestToBeForgotten' )->getLocalURL(),
				],
				wfMessage( 'rbtf-prefs-info' )->text()
			),
			'section' => 'personal/info'
		];
	}
}