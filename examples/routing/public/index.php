<?php

declare(strict_types=1);

use Mithridatem\Routing\Exception\RouteNotFoundException;

$router = require dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
try {
    echo $router->dispatch();
} catch (RouteNotFoundException) {
    http_response_code(404);
    echo 'Route introuvable';
}
