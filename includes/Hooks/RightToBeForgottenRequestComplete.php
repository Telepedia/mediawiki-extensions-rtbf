<?php

namespace Telepedia\Extensions\RequestToBeForgotten\Hooks;

use Telepedia\Extensions\RequestToBeForgotten\RTBFRequest;

interface RightToBeForgottenRequestComplete {

	/**
	 * Called when all jobs on all wikis complete for anonymisation of this user.
	 * @param RTBFRequest $request
	 * @return void
	 */
	public function onRightToBeForgottenComplete( RTBFRequest $request ): void;
}