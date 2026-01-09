<?php

namespace Telepedia\Extensions\RequestToBeForgotten;

use MediaWiki\MediaWikiServices;
use Telepedia\Extensions\RequestToBeForgotten\Hooks\RightToBeForgottenHookRunner;

return [

	'RTBFService' => static function (
		MediaWikiServices $services
	): RTBFService {
		return new RTBFService(
			$services->getUserFactory(),
			$services->getConnectionProvider(),
			$services->get( 'UAM.GlobalUserService' ),
			$services->getJobQueueGroupFactory(),
			$services->get( 'RightToBeForgottenHookRunner' )
		);
	},

	'RightToBeForgottenHookRunner' => static function ( MediaWikiServices $services ): RightToBeForgottenHookRunner {
		return new RightToBeForgottenHookRunner( $services->getHookContainer() );
	},
];