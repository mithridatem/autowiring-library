<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring;

use Closure;
use Mithridatem\Autowiring\Exception\ContainerException;
use Mithridatem\Autowiring\Exception\NotFoundException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/** Object services are shared for the lifetime of this container. */
final class Container implements ContainerInterface
{
    /** @var array<string, string> */
    private array $bindings = [];
    /** @var array<string, Closure> */
    private array $factories = [];
    /** @var array<string, object> */
    private array $values = [];
    /** @var array<class-string, array<string, mixed>> */
    private array $parameters = [];
    /** @var array<string, object> */
    private array $instances = [];
    /** @var array<string, true> */
    private array $resolving = [];
    private bool $locked = false;

    /** Bind an interface or parent class to an instantiable implementation. */
    public function bind(string $id, string $implementation): self
    {
        $this->assertConfigurable($id);
        if ((!interface_exists($id) && !class_exists($id))
            || !class_exists($implementation)
            || !is_a($implementation, $id, true)
            || !(new ReflectionClass($implementation))->isInstantiable()
            || $id === $implementation) {
            throw new ContainerException("Invalid binding: $id -> $implementation");
        }

        unset($this->factories[$id], $this->values[$id]);
        $this->bindings[$id] = $implementation;

        return $this;
    }

    /** Register an existing object (for example, an already configured PDO). */
    public function set(string $id, object $instance): self
    {
        $this->assertConfigurable($id);
        $this->assertServiceType($id, $instance);
        unset($this->bindings[$id], $this->factories[$id]);
        $this->values[$id] = $instance;

        return $this;
    }

    /** The factory receives this container; it must return an object. */
    public function factory(string $id, callable $factory): self
    {
        $this->assertConfigurable($id);
        unset($this->bindings[$id], $this->values[$id]);
        $this->factories[$id] = Closure::fromCallable($factory);

        return $this;
    }

    /** Configure constructor arguments by their exact parameter names. */
    public function configure(string $class, array $parameters): self
    {
        $this->assertConfigurable($class);
        if (!class_exists($class)) {
            throw new ContainerException("Unknown class: $class");
        }

        $constructor = (new ReflectionClass($class))->getConstructor();
        $names = array_map(static fn (ReflectionParameter $p): string => $p->getName(), $constructor?->getParameters() ?? []);
        foreach ($parameters as $name => $_value) {
            if (!in_array($name, $names, true)) {
                throw new ContainerException("Unknown constructor parameter: $class::\$$name");
            }
        }

        $this->parameters[$class] = array_replace($this->parameters[$class] ?? [], $parameters);

        return $this;
    }

    /** Reports an entry's availability, not whether its full graph can be built. */
    public function has(string $id): bool
    {
        return isset($this->instances[$id])
            || isset($this->bindings[$id])
            || isset($this->factories[$id])
            || isset($this->values[$id])
            || (class_exists($id) && (new ReflectionClass($id))->isInstantiable());
    }

    public function get(string $id): object
    {
        // Avoid stale object graphs: all definitions are configured before resolution.
        $this->locked = true;
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->resolving[$id])) {
            throw new ContainerException('Circular dependency: ' . implode(' -> ', [...array_keys($this->resolving), $id]));
        }
        if (!$this->has($id)) {
            throw new NotFoundException("Service not found: $id");
        }

        $this->resolving[$id] = true;
        try {
            if (isset($this->bindings[$id])) {
                $instance = $this->get($this->bindings[$id]);
            } elseif (isset($this->values[$id])) {
                $instance = $this->values[$id];
            } elseif (isset($this->factories[$id])) {
                $instance = ($this->factories[$id])($this);
            } else {
                $instance = $this->build($id);
            }

            if (!is_object($instance)) {
                throw new ContainerException("Factory must return an object: $id");
            }
            $this->assertServiceType($id, $instance);

            return $this->instances[$id] = $instance;
        } catch (Throwable $error) {
            // A missing nested dependency is a construction error, not a missing root.
            throw new ContainerException("Cannot resolve $id: " . $error->getMessage(), 0, $error);
        } finally {
            unset($this->resolving[$id]);
        }
    }

    private function build(string $class): object
    {
        $reflection = new ReflectionClass($class);
        if ($reflection->isInternal()) {
            throw new ContainerException("Register a factory or instance for internal class: $class");
        }
        $arguments = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isPassedByReference() || $parameter->isVariadic()) {
                throw new ContainerException("Use a factory for reference/variadic parameter: $class::\$" . $parameter->getName());
            }
            $configured = $this->parameters[$class] ?? [];
            if (array_key_exists($parameter->getName(), $configured)) {
                $arguments[] = $configured[$parameter->getName()];
                continue;
            }

            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $name = $type->getName();
                $declaringClass = $parameter->getDeclaringClass();
                $name = match ($name) {
                    'self' => $declaringClass->getName(),
                    'parent' => $declaringClass->getParentClass()->getName(),
                    default => $name,
                };
                if ($this->has($name)) {
                    $arguments[] = $this->get($name);
                    continue;
                }
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new ContainerException("Unresolvable parameter: $class::\$" . $parameter->getName() . '. Use bind(), configure() or factory().');
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function assertConfigurable(string $id): void
    {
        if ($this->locked) {
            throw new ContainerException('Configure the container before the first get() call.');
        }
        if ($id === '') {
            throw new ContainerException('Service identifiers must not be empty.');
        }
    }

    private function assertServiceType(string $id, object $instance): void
    {
        if ((class_exists($id) || interface_exists($id)) && !$instance instanceof $id) {
            throw new ContainerException("Service does not implement or extend $id");
        }
    }
}
