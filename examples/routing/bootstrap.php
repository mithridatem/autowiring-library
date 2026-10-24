<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mithridatem\Autowiring\Bridge\Routing\ContainerControllerResolver;
use Mithridatem\Autowiring\Container;
use Mithridatem\Routing\Loader\AttributeRouteLoader;
use Mithridatem\Routing\Router;

$container = (new Container())->load(__DIR__ . '/config/services.php');
$router = new Router(controllerResolver: new ContainerControllerResolver($container));

(new AttributeRouteLoader())->loadDirectory(
    router: $router,
    directory: __DIR__ . '/src/Controller',
    namespace: 'Example\\Controller'
);

return $router;
