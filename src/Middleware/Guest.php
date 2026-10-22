<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Middleware;
use HugeBear42\Utils\Auth\Session;
use HugeBear42\Utils\Core\Logger;
class Guest
{
    public function handle() : void
    {
        if( Session::check() )
        {
            Logger::fine("Guest: already logged in, sent to the home page");
            header('location: /');
            exit();
        }
    }
}