<?php

namespace Telepedia\Extensions\RequestToBeForgotten;

class RTBFRequest {

	public function __construct(
		public int $id,
		public int $userId,
		public string $originalUsername,
		public int $status,
		public ?string $confirmationToken,
		public ?string $targetUsername,
		public int $tokenExpiration,
		public int $createdAt,
		public ?int $finishedAt
	) {}

	/**
	 * Can we actually execute this request? Only if
	 * A) the user initiated this request themselves and have confirmed the email
	 * B) the request was initiated from the CLI by a staff member and is therefore bypassing
	 * the email confirmation
	 * @return bool
	 */
	public function canExecuteRequest(): bool {
		return $this->status === RTBFService::STATUS_CONFIRMED_WAITING
			|| $this->status === RTBFService::STATUS_IN_PROGRESS;
	}
}