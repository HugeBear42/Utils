<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Auth;
/**
	2026-10-22	v1.00	frank.a.hubert@gmail.com: The outcome of Users::attemptLogin().
	                    A login page should show the same message for INVALID & INACTIVE, so it does not tell which usernames exist.
**/

final class LoginResult
{
	public const OK = 'ok';
	public const INVALID = 'invalid';		// Unknown username or wrong password
	public const INACTIVE = 'inactive';		// Right password, but the user was deactivated
	public const LOCKED = 'locked';			// Too many failed attempts, see $lockedUntil

	public function __construct(
		public readonly string $status,
		public readonly ?User $user = null,			// Set when the status is OK
		public readonly ?string $lockedUntil = null,	// UTC, yyyy-mm-dd hh:mm:ss, set when the status is LOCKED
		public readonly bool $lockedNow = false,	// True if this attempt locked the user (worth an event or a log line)
	)
	{}

	public function ok() : bool
	{	return $this->status === self::OK;	}
}
