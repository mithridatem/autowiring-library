<?php

declare(strict_types=1);

use Mithridatem\Autowiring\Container;
use Psr\Container\ContainerInterface;

// The application classes are declared by examples/attributes.php.
return static function (Container $container): void {
    $container->configure(ReportFormatter::class, ['prefix' => 'Rapport : ']);
    $container->factory('formatter.report', static fn (ContainerInterface $c) => $c->get(ReportFormatter::class));
};
