<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Tests;

use Mithridatem\Autowiring\Container;
use Mithridatem\Autowiring\Exception\ContainerException;
use Mithridatem\Autowiring\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use stdClass;

final class ContainerTest extends TestCase
{
    public function testRecursiveAutowiringAndSharedAliases(): void
    {
        $container = (new Container())->bind(StoreInterface::class, Store::class);
        $controller = $container->get(Controller::class);
        self::assertInstanceOf(ContainerInterface::class, $container);
        self::assertSame('books', $controller->service->store->name());
        self::assertSame($controller, $container->get(Controller::class));
        self::assertSame($container->get(Store::class), $container->get(StoreInterface::class));
        self::assertNotSame($container->get(Store::class), (new Container())->get(Store::class));
    }

    public function testExplicitParametersOverrideDefaultsAndSupportNull(): void
    {
        $container = (new Container())->configure(Configured::class, ['path' => '/var/log/app.log', 'label' => null]);
        $value = $container->get(Configured::class);
        self::assertSame('/var/log/app.log', $value->path);
        self::assertNull($value->label);
    }

    public function testDefaultsIncludingUnboundOptionalInterface(): void
    {
        $value = (new Container())->get(Defaults::class);
        self::assertSame('default', $value->label);
        self::assertNull($value->store);
    }

    public function testConfiguredObjectArgumentAndFactoryAreSupported(): void
    {
        $store = new Store();
        $container = (new Container())->configure(Service::class, ['store' => $store]);
        self::assertSame($store, $container->get(Service::class)->store);
        $calls = 0;
        $container = (new Container())
            ->set(StoreInterface::class, $store)
            ->factory(Service::class, function (ContainerInterface $container) use (&$calls): Service {
                ++$calls;
                return new Service($container->get(StoreInterface::class));
            });
        self::assertSame($store, $container->get(Service::class)->store);
        self::assertSame($container->get(Service::class), $container->get(Service::class));
        self::assertSame(1, $calls);
    }

    public function testHasDoesNotInstantiateAndDoesNotGuaranteeResolvableGraph(): void
    {
        $container = (new Container())->factory('named-service', fn () => throw new RuntimeException('not called'));
        self::assertTrue($container->has('named-service'));
        self::assertTrue($container->has(Configured::class));
        self::assertFalse($container->has(StoreInterface::class));
        self::assertFalse($container->has('unknown-service'));
        self::assertFalse($container->has(AbstractStore::class));
        $this->expectException(ContainerException::class);
        $container->get(Configured::class);
    }

    public function testMissingRootUsesPsrNotFoundException(): void
    {
        try {
            (new Container())->get('unknown-service');
            self::fail('Expected exception');
        } catch (NotFoundException $error) {
            self::assertInstanceOf(NotFoundExceptionInterface::class, $error);
            self::assertInstanceOf(ContainerExceptionInterface::class, $error);
        }
    }

    public function testMissingNestedDependencyIsNotAMissingRoot(): void
    {
        try {
            (new Container())->factory('known', fn (ContainerInterface $c) => $c->get('missing'))->get('known');
            self::fail('Expected exception');
        } catch (ContainerException $error) {
            self::assertNotInstanceOf(NotFoundExceptionInterface::class, $error);
            self::assertInstanceOf(NotFoundExceptionInterface::class, $error->getPrevious());
        }
    }

    public function testCyclesIncludeDependencyChain(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency: ' . CycleA::class . ' -> ' . CycleB::class . ' -> ' . CycleA::class);
        (new Container())->get(CycleA::class);
    }

    public function testFactoryCycleIsDetected(): void
    {
        $container = (new Container())->factory('cycle', fn (ContainerInterface $c) => $c->get('cycle'));
        $this->expectExceptionMessage('Circular dependency: cycle -> cycle');
        $container->get('cycle');
    }

    public function testFailureDoesNotLeaveResolutionStackDirty(): void
    {
        $attempts = 0;
        $container = (new Container())->factory('retry', function () use (&$attempts): object {
            if (++$attempts === 1) {
                throw new RuntimeException('temporary failure');
            }
            return new stdClass();
        });
        try {
            $container->get('retry');
            self::fail('Expected exception');
        } catch (ContainerException $error) {
            self::assertInstanceOf(RuntimeException::class, $error->getPrevious());
        }
        self::assertInstanceOf(stdClass::class, $container->get('retry'));
        self::assertSame(2, $attempts);
    }

    public function testConfigurationIsFrozenAfterResolution(): void
    {
        $container = new Container();
        $container->get(Store::class);
        $this->expectExceptionMessage('before the first get()');
        $container->set(StoreInterface::class, new Store());
    }

    public function testInvalidBindingIsRejected(): void
    {
        $this->expectExceptionMessage('Invalid binding');
        (new Container())->bind(StoreInterface::class, stdClass::class);
    }

    public function testUnknownParameterIsRejected(): void
    {
        $this->expectExceptionMessage('Unknown constructor parameter');
        (new Container())->configure(Configured::class, ['paht' => '/tmp']);
    }

    public function testInvalidInstanceIsRejected(): void
    {
        $this->expectExceptionMessage('does not implement');
        (new Container())->set(StoreInterface::class, new stdClass());
    }

    public function testInvalidFactoryReturnIsRejected(): void
    {
        $this->expectExceptionMessage('must return an object');
        (new Container())->factory('bad', fn () => 42)->get('bad');
    }

    public function testFactoryMustRespectClassIdentifier(): void
    {
        $this->expectExceptionMessage('does not implement');
        (new Container())->factory(StoreInterface::class, fn () => new stdClass())->get(StoreInterface::class);
    }

    public function testConstructorTypeErrorIsWrapped(): void
    {
        $this->expectException(ContainerException::class);
        (new Container())->configure(Configured::class, ['path' => []])->get(Configured::class);
    }

    public function testUnionCanBeConfiguredExplicitly(): void
    {
        $value = (new Container())->configure(UnionArgument::class, ['value' => 42])->get(UnionArgument::class);
        self::assertSame(42, $value->value);
    }

    public function testUnionIsNotGuessed(): void
    {
        $this->expectExceptionMessage('Unresolvable parameter');
        (new Container())->get(UnionArgument::class);
    }

    public function testVariadicRequiresFactory(): void
    {
        $this->expectExceptionMessage('Use a factory');
        (new Container())->get(VariadicArgument::class);
    }

    public function testInternalClassCanBeProvidedExplicitly(): void
    {
        $instance = new stdClass();
        self::assertSame($instance, (new Container())->set(stdClass::class, $instance)->get(stdClass::class));
    }

    public function testInternalClassIsNotAutowired(): void
    {
        $this->expectExceptionMessage('internal class');
        (new Container())->get(stdClass::class);
    }
}

interface StoreInterface { public function name(): string; }
final class Store implements StoreInterface { public function name(): string { return 'books'; } }
abstract class AbstractStore {}
final class Service { public function __construct(public StoreInterface $store) {} }
final class Controller { public function __construct(public Service $service) {} }
final class Configured { public function __construct(public string $path, public ?string $label = 'default') {} }
final class Defaults { public function __construct(public string $label = 'default', public ?StoreInterface $store = null) {} }
final class CycleA { public function __construct(CycleB $b) {} }
final class CycleB { public function __construct(CycleA $a) {} }
final class UnionArgument { public function __construct(public int|string $value) {} }
final class VariadicArgument { public function __construct(string ...$values) {} }
