<?php

namespace Telepedia\Extensions\RequestToBeForgotten\Hooks;

interface RightToBeForgottenTableDeletions {

	/**
	 * Allows modification - addition or removal - of tables where data will be deleted from
	 * @param array $tables
	 * @return void
	 */
	public function onRightToBeForgottenTableDeletions( array &$tables ): void;
}