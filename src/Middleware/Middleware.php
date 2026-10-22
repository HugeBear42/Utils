<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Middleware;

use Exception;
use HugeBear42\Utils\Core\Logger;
/**
	2026-10-22	v1.10	frank.a.hubert@gmail.com: 'can:<permission>' (see Can), deny() shared by the middleware
**/

class Middleware
{
    const MAP = [
        'guest'=>Guest::class,
        'auth'=>Auth::class,
        'can'=>Can::class,      // With a parameter: 'can:jobs.manage'
    ];

    // Called by deny() instead of ending the script, for the tests: fn(int $status, string $message, ?string $redirect)
    public static ?\Closure $onDeny = null;

    /**
     * @throws Exception
     */
    public static function resolve(string|null $key) : void
    {
        if(!$key)
        {   return; }
        [$name, $parameter] = array_pad(explode(':', $key, 2), 2, null);
        if(!array_key_exists($name, self::MAP))
        {   throw new Exception("Middleware: $key is not an instance of Middleware"); }
        if($name === 'can' && ($parameter === null || $parameter === ''))
        {   throw new Exception("Middleware: 'can' needs a permission, e.g. can:jobs.manage"); }
        $middleware = static::MAP[$name];
        Logger::fine("Middleware mapped to: $key");
        ($parameter === null ? new $middleware : new $middleware($parameter))->handle();
    }

    /**
     * Refuses the request & ends the script: a JSON request gets {"error": $message} with $status,
     * a page request is sent to $redirect if given, otherwise gets $status with a short text.
     */
    public static function deny(int $status, string $message, ?string $redirect = null) : void
    {
        if(self::$onDeny !== null)
        {
            (self::$onDeny)($status, $message, $redirect);
            return;
        }
        if(self::wantsJson())
        {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $message]);
        }
        else if($redirect !== null)
        {   header('Location: '.$redirect); }
        else
        {
            http_response_code($status);
            header('Content-Type: text/plain; charset=utf-8');
            echo $message;
        }
        exit();
    }

    // True for a fetch() / XMLHttpRequest call expecting JSON, rather than a page opened in the browser
    public static function wantsJson() : bool
    {
        return str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
            || str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')
            || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }
}
