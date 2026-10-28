<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Construction;

use Mithridatem\Autowiring\Attribute\Inject;
use Mithridatem\Autowiring\Exception\ContainerException;
use Psr\Container\ContainerInterface;
use ReflectionNamedType;
use ReflectionParameter;

/** Resolves a single constructor argument without managing service lifetimes. */
final class ConstructorArgumentResolver
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /** @param array<string, mixed> $configured */
    public function resolve(string $class, ReflectionParameter $parameter, array $configured): mixed
    {
        if ($parameter->isPassedByReference() || $parameter->isVariadic()) {
            throw new ContainerException("Use a factory for reference/variadic parameter: $class::\$" . $parameter->getName());
        }

        if (array_key_exists($parameter->getName(), $configured)) {
            return $configured[$parameter->getName()];
        }

        $type = $parameter->getType();
        $attributes = $parameter->getAttributes(Inject::class);
        if ($attributes !== []) {
            $location = $class . '::$' . $parameter->getName();
            if (count($attributes) !== 1) {
                throw new ContainerException("Only one Inject attribute is allowed: $location");
            }
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw new ContainerException("Inject requires a named object type: $location. Use configure() for values.");
            }
            $id = $attributes[0]->newInstance()->id;
            $instance = $this->container->get($id);
            $expected = $this->parameterClass($parameter, $type);
            if (!$instance instanceof $expected) {
                throw new ContainerException("Service '$id' must implement or extend $expected for $location");
            }

            return $instance;
        }

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $name = $this->parameterClass($parameter, $type);
            if ($this->container->has($name)) {
                return $this->container->get($name);
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new ContainerException("Unresolvable parameter: $class::\$" . $parameter->getName() . '. Use bind(), configure() or factory().');
    }

    private function parameterClass(ReflectionParameter $parameter, ReflectionNamedType $type): string
    {
        return match ($type->getName()) {
            'self' => $parameter->getDeclaringClass()->getName(),
            'parent' => $parameter->getDeclaringClass()->getParentClass()->getName(),
            default => $type->getName(),
        };
    }
}
