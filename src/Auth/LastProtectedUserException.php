<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Auth;
/**
	2026-10-22	v1.00	frank.a.hubert@gmail.com: Thrown by Users when a change would leave no active user of the protected role
	                    (see Users::configure() 'protectedRole'), e.g. deactivating the last administrator.
	                    The message can be shown to the user as it is.
**/

final class LastProtectedUserException extends \RuntimeException
{
	public function __construct(public readonly string $username, public readonly string $role)
	{	parent::__construct("'$username' is the last active $role and cannot be removed, deactivated or given another role");	}
}
