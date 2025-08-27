<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Middleware;
use HugeBear42\Utils\Core\Logger;
class Guest
{
    public function handle() : void
    {
        if( $_SESSION['user'] ?? false )
        {
            Logger::error("Guest: user not authorised!");
            header('location: /');
            exit();
        }
    }
}