<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Middleware;
use HugeBear42\Utils\Auth\Session;
use HugeBear42\Utils\Core\Logger;
/**
	2026-10-22	v1.10	frank.a.hubert@gmail.com: Fixed the check (! bound tighter than ??, a missing user raised a warning),
	                    a JSON request gets a JSON 401 instead of a redirect, the login page comes from Session::loginUrl()
**/
class Auth
{
    public function handle() : void
    {
        if(!Session::check())
        {
            Logger::info('Auth: not logged in, sent to the login page');
            Middleware::deny(401, 'Not logged in', Session::loginUrl());
        }
    }
}
