<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Bridge\Routing;

use Mithridatem\Routing\Controller\ControllerResolverInterface;
use Mithridatem\Routing\Exception\RouterException;
use Mithridatem\Routing\Handler\ControllerReference;
use Psr\Container\ContainerInterface;
use Throwable;

/** Works with this library or any PSR-11 container. Requires mithridatem/router. */
final class ContainerControllerResolver implements ControllerResolverInterface
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function resolve(ControllerReference $controller): callable
    {
        $class = $controller->getClassName();
        $method = $controller->getMethodName();
        try {
            $instance = $this->container->get($class);
        } catch (Throwable $error) {
            throw new RouterException("Cannot resolve controller: $class", 0, $error);
        }

        if (!is_object($instance) || !$instance instanceof $class || !is_callable([$instance, $method])) {
            throw new RouterException("Invalid controller action: $class::$method");
        }

        return [$instance, $method];
    }
}
