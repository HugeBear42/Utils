<?php

declare(strict_types=1);

namespace HugeBear42\Utils\Core;

use HugeBear42\Utils\Middleware\Middleware;

class Router
{

    public array $routes=[];

    private function add(string $method, string $uri, string $controller) : object
    {
        $this->routes[]=['uri'=>$uri, 'controller'=>$controller, 'method'=>strtoupper($method), 'middleware'=>null];
        return $this;
    }
    public function get(string $uri, string $controller) : object
    {
        return $this->add('GET', $uri, $controller);
    }
    public function post(string $url, string $controller) : object
    {
        return $this->add('POST', $url, $controller);
    }

    public function delete(string $url, string $controller) : object
    {
        return $this->add('DELETE', $url, $controller);
    }

    public function patch(string $url, string $controller) : object
    {
        return $this->add('PATCH', $url, $controller);
    }

    public function put(string $url, string $controller) : object
    {
        return $this->add('PUT', $url, $controller);
    }

    public function route(string $uri, string $method)
    {
        Logger::fine("Resolving route $method to $uri");
        foreach($this->routes as $route)
        {
            if( $route['uri']=== $uri && $route['method']===strtoupper($method) )
            {
                Middleware::resolve($route['middleware']);
//                if($route['middleware'])
//                {
//                    $middleware=Middleware::MAP[$route['middleware']];
//                    Logger::info("Middleware: {$route['middleware']}");
//                    (new $middleware)->handle();
//                }
//                else
//                    Logger::info("No middleware found for route ".$route['uri']);
                //Logger::fine("REDIRECT: ".basePath($route['controller']));
                // Apply the Middleware

                return require basePath($route['controller']);
            }
        }
       $this->abort();
    }
    function abort($code=404) : void
    {
        http_response_code($code);
        require basePath("views/$code.php");
        die();
    }

    public function only(string $key) : object
    {
        $this->routes[array_key_last($this->routes)]['middleware'] = $key;
        return $this;
    }
}
