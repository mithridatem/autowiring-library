<?php

declare(strict_types=1);

use Example\Repository\BookRepositoryInterface;
use Example\Repository\InMemoryBookRepository;
use Mithridatem\Autowiring\Container;

return static function (Container $container): void {
    $container->bind(BookRepositoryInterface::class, InMemoryBookRepository::class);
};
