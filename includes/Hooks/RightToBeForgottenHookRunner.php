<?php

namespace Telepedia\Extensions\RequestToBeForgotten\Hooks;

use MediaWiki\HookContainer\HookContainer;
use Telepedia\Extensions\RequestToBeForgotten\RTBFRequest;

class RightToBeForgottenHookRunner implements
	RightToBeForgottenRequestComplete,
	RightToBeForgottenTableDeletions,
	RightToBeForgottenTableReplacements {

	public function __construct( private readonly HookContainer $container ) {}

	/**
	 * @inheritDoc
	 */
	public function onRightToBeForgottenComplete( RTBFRequest $request ): void {
		$this->container->run(
			'RightToBeForgottenComplete',
			[ $request ],
			[ 'abortable' => false ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onRightToBeForgottenTableDeletions( array &$tables ): void {
		$this->container->run(
			'RightToBeForgottenTableDeletions',
			[ &$tables ],
			[ 'abortable' => false ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onRightToBeForgottenTableReplacements( array &$tables ): void {
		$this->container->run(
			'RightToBeForgottenTableReplacements',
			[ &$tables ],
			[ 'abortable' => false ]
		);
	}
}