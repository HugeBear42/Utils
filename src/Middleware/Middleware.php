<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Middleware;

use Exception;
use HugeBear42\Utils\Core\Logger;

class Middleware
{
    const MAP = [
        'guest'=>Guest::class,
        'auth'=>Auth::class,
    ];

    /**
     * @throws Exception
     */
    public static function resolve(string|null $key) : void
    {
        if(!$key)
        {   return; }
        if(array_key_exists($key, self::MAP)) {
            $middleware = static::MAP[$key];
            Logger::fine("Middleware mapped to: $key");
            (new $middleware)->handle();
        }
        else
            throw new Exception("Middleware: $key is not an instance of Middleware");
    }
}
