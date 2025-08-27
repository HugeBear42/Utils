<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Core;

class Validator
{
    public static function string(string $value, int $min=1, int $max=PHP_INT_MAX) : bool
    {
        $length = strlen(trim($value));
        return $length>=$min && $length<=$max;
    }
    public static function email($email) : bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public static function sanitizeFileName(string $filename) : string
    {
        $filename = basename($filename);                                                    // Remove any path info (in case it's passed)
        $filename = preg_replace("/[^a-zA-Z0-9.\-_]/", "_", $filename);   // Replace anything not alphanumeric, dot, hyphen, or underscore
        $filename = substr($filename, 0, 512);                                 // Optionally, limit length
        return $filename;
    }
}