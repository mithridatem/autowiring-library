<?php

declare(strict_types=1);

use Mithridatem\Autowiring\Container;

return static function (Container $container): void {
    $container->discover(__DIR__ . '/../src/Repository', 'Example\\Repository');
};
