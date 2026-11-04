<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Factory;

use Psr\Container\ContainerInterface;

/**
 * Contract for reusable service factories.
 *
 * The container validates the returned object against the requested service id.
 */
interface FactoryInterface
{
    public function create(ContainerInterface $container): object;
}
