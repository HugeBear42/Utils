<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Middleware;
use HugeBear42\Utils\Core\Logger;
class Auth
{
    public function handle() : void
    {
        if(! $_SESSION['user'] ?? false )
        {
            Logger::error("Auth: user not authorised!");
            header('location: /login');
            exit();
        }
    }
}