<?php

function dd($value): void
{
    echo "<pre>";
    var_dump($value);
    echo "</pre>";
    die();
}

function urlIs($value): bool
{
    return $_SERVER['REQUEST_URI'] === $value;
}
function queryStringToArray(string $queryString): array
{
    $array=[];
    parse_str($queryString, $array);
    return $array;
}

function authorise($condition, $status=403)
{
 //   if(! $condition)
 //       abort($status);
}

function basePath($path) : string
{
    return BASE_PATH.$path; // BASE_PATH From index.php
}
function view($path, $attributes=[]) : void
{
    extract($attributes);   // imports variables into current symbol table from associative array
    require basePath('views/'.$path);
}

function login(array $user): void
{
    $_SESSION['user'] = [ 'email' => $user['email']];
}
