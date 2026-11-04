<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Factory;

use Closure;
use Mithridatem\Autowiring\Exception\ContainerException;

/** Stores factory definitions without constructing services. */
final class FactoryRegistry
{
    /** @var array<string, Closure|FactoryInterface> */
    private array $definitions = [];

    public function register(string $id, callable|FactoryInterface $factory): void
    {
        $this->definitions[$id] = $factory instanceof FactoryInterface
            ? $factory
            : Closure::fromCallable($factory);
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    public function get(string $id): Closure|FactoryInterface
    {
        if (!$this->has($id)) {
            throw new ContainerException("Factory not found: $id");
        }

        return $this->definitions[$id];
    }

    public function remove(string $id): void
    {
        unset($this->definitions[$id]);
    }

    /** @return array<string, Closure|FactoryInterface> */
    public function snapshot(): array
    {
        return $this->definitions;
    }

    /** @param array<string, Closure|FactoryInterface> $definitions */
    public function restore(array $definitions): void
    {
        $this->definitions = $definitions;
    }
}
