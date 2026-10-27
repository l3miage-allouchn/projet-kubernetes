<?php

class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable $action): void
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'action' => $action,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $pattern = preg_replace('#\{[^/]+\}#', '([^/]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                array_shift($matches);
                call_user_func_array($route['action'], $matches);
                return;
            }
        }

        // aucune route ne correspond : page 404
        (new ErrorController())->notFound();
    }
}
