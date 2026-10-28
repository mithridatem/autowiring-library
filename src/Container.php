<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring;

use Closure;
use Mithridatem\Autowiring\Construction\ConstructorArgumentResolver;
use Mithridatem\Autowiring\Construction\ObjectBuilder;
use Mithridatem\Autowiring\Discovery\ServiceDiscovery;
use Mithridatem\Autowiring\Exception\ContainerException;
use Mithridatem\Autowiring\Exception\NotFoundException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
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
    /** @var array<string, true> Configuration files currently being loaded. */
    private array $loadingFiles = [];
    /** @var array<class-string, list<class-string>> */
    private array $discovered = [];

    /** Register implementations from an explicit PSR-4 directory/namespace pair. */
    public function discover(string $directory, string $namespace): self
    {
        $this->assertConfigurable($directory);
        try {
            $candidates = (new ServiceDiscovery())->discover($directory, $namespace);
        } catch (Throwable $error) {
            throw new ContainerException("Cannot discover services in $directory: " . $error->getMessage(), 0, $error);
        }
        foreach ($candidates as $interface => $classes) {
            $merged = array_values(array_unique([...($this->discovered[$interface] ?? []), ...$classes]));
            sort($merged, SORT_STRING);
            $this->discovered[$interface] = $merged;
        }

        return $this;
    }

    /** Load a trusted PHP file returning a Closure(Container): void. */
    public function load(string $file): self
    {
        $this->assertConfigurable($file);
        $path = realpath($file);
        if ($path === false || !is_file($path) || !is_readable($path)) {
            throw new ContainerException("Configuration file is not readable: $file");
        }
        if (isset($this->loadingFiles[$path])) {
            throw new ContainerException("Circular configuration import: $path");
        }

        $snapshot = [$this->bindings, $this->factories, $this->values, $this->parameters, $this->discovered];
        $this->loadingFiles[$path] = true;
        try {
            $configure = (static fn (string $path) => require $path)($path);
            if (!$configure instanceof Closure) {
                throw new ContainerException('Configuration must return a Closure accepting the container.');
            }
            $configure($this);
        } catch (Throwable $error) {
            [$this->bindings, $this->factories, $this->values, $this->parameters, $this->discovered] = $snapshot;
            throw new ContainerException("Cannot load configuration $path: " . $error->getMessage(), 0, $error);
        } finally {
            unset($this->loadingFiles[$path]);
        }

        return $this;
    }

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
            || isset($this->discovered[$id])
            || (class_exists($id) && (new ReflectionClass($id))->isInstantiable());
    }

    public function get(string $id): object
    {
        if ($this->loadingFiles !== []) {
            throw new ContainerException('Do not resolve services while loading configuration; register factories instead.');
        }
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
            } elseif (isset($this->discovered[$id])) {
                $classes = $this->discovered[$id];
                if (count($classes) !== 1) {
                    throw new ContainerException("Ambiguous implementations for $id: " . implode(', ', $classes) . '. Use bind(), set(), factory() or Inject to select a service.');
                }
                $instance = $this->get($classes[0]);
            } else {
                $instance = (new ObjectBuilder(new ConstructorArgumentResolver($this)))->build($id, $this->parameters[$id] ?? []);
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
