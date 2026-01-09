<?php

namespace Telepedia\Extensions\RequestToBeForgotten\Hooks;

interface RightToBeForgottenTableReplacements {

	/**
	 * Allows modification of the tables which a users data will be replaced in - this is different from the
	 * hook which allows you to add, or remove from, the tables which data will be deleted from. Use this hook
	 * if the entry MUST remain in the table, but should have PII removed from it.
	 * @param array $tables
	 * @return void
	 */
	public function onRightToBeForgottenTableReplacements( array &$tables ): void;
}