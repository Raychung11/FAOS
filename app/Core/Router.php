<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string,array<int,array{pattern:string,handler:mixed,perm:?string}>> */
    private array $routes = ['GET' => [], 'POST' => [], 'PUT' => [], 'DELETE' => [], 'PATCH' => []];

    public function get(string $p, $h, ?string $perm = null): void    { $this->add('GET', $p, $h, $perm); }
    public function post(string $p, $h, ?string $perm = null): void   { $this->add('POST', $p, $h, $perm); }
    public function put(string $p, $h, ?string $perm = null): void    { $this->add('PUT', $p, $h, $perm); }
    public function delete(string $p, $h, ?string $perm = null): void { $this->add('DELETE', $p, $h, $perm); }

    private function add(string $m, string $path, $handler, ?string $perm): void
    {
        $pattern = preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $path);
        $this->routes[$m][] = ['pattern' => '#^' . $pattern . '$#', 'handler' => $handler, 'perm' => $perm];
    }

    public function dispatch(Request $req): void
    {
        $method = $req->method();
        $path = $req->path();

        // CSRF for state-changing requests (skip pure JSON API w/ same-origin token header handled in controllers).
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            if (!Csrf::check($req)) {
                if ($req->wantsJson()) {
                    Response::fail('Invalid or missing CSRF token', 419);
                }
                Response::html('<h1>419</h1><p>CSRF token mismatch. Refresh and retry.</p>', 419);
            }
        }

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['pattern'], $path, $m)) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

                if ($route['perm'] !== null) {
                    Auth::requirePermission($req, $route['perm']);
                }

                $handler = $route['handler'];
                if (is_array($handler)) {
                    [$class, $action] = $handler;
                    $controller = new $class();
                    $controller->$action($req, $params);
                    return;
                }
                $handler($req, $params);
                return;
            }
        }

        if ($req->wantsJson()) {
            Response::fail('Route not found', 404);
        }
        Response::html(View::render('errors.404', ['title' => 'Not Found'], 'blank'), 404);
    }
}
