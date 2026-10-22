<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Middleware;
use HugeBear42\Utils\Auth\Session;
use HugeBear42\Utils\Core\Logger;
/**
	2026-10-22	v1.00	frank.a.hubert@gmail.com: Lets a request through only if the logged-in user has a permission (see Session::can()).
	                    In the routes: $router->post('/resendJob', 'controllers/jobs/resend.php')->only('can:jobs.manage');
	                    Not logged in: as Auth (login page or JSON 401). Logged in without the permission: 403.
**/
class Can
{
    public function __construct(private readonly string $permission)
    {}

    public function handle() : void
    {
        (new Auth)->handle();
        if(!Session::can($this->permission))
        {
            Logger::warning("Can: '".Session::username()."' (".Session::role().") lacks the permission {$this->permission}");
            Middleware::deny(403, 'Forbidden');
        }
    }
}
