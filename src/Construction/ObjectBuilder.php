<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Construction;

use Mithridatem\Autowiring\Exception\ContainerException;
use ReflectionClass;

/** Constructs objects using their constructor and a dedicated argument resolver. */
final class ObjectBuilder
{
    public function __construct(private readonly ConstructorArgumentResolver $arguments)
    {
    }

    /** @param array<string, mixed> $configured */
    public function build(string $class, array $configured = []): object
    {
        $reflection = new ReflectionClass($class);
        if ($reflection->isInternal()) {
            throw new ContainerException("Register a factory or instance for internal class: $class");
        }

        $arguments = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $arguments[] = $this->arguments->resolve($class, $parameter, $configured);
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
