<?php
declare(strict_types=1);

/**
 * FAOS front controller. All web traffic routes through here.
 */

require dirname(__DIR__) . '/app/Core/bootstrap.php';

use App\Core\Request;
use App\Core\Router;
use App\Core\Session;

// Security headers (baseline; tighten CSP per deployment).
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header_remove('X-Powered-By');

Session::start();

$request = new Request();

/** @var Router $router */
$router = require dirname(__DIR__) . '/app/routes.php';
$router->dispatch($request);
