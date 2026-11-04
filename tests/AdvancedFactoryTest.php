<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Tests;

use Mithridatem\Autowiring\Container;
use Mithridatem\Autowiring\Exception\ContainerException;
use Mithridatem\Autowiring\Factory\FactoryInterface;
use Mithridatem\Autowiring\Factory\FactoryRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

final class AdvancedFactoryTest extends TestCase
{
    public function testClassFactorySupportsAutowiredInterfaceAndSharesResult(): void
    {
        $calls = 0;
        $container = (new Container())->factory(
            AdvancedStoreInterface::class,
            new AdvancedStoreFactory($calls)
        );

        self::assertTrue($container->has(AdvancedStoreInterface::class));
        self::assertSame(0, $calls, 'has() must not execute the factory');

        $service = $container->get(AdvancedStoreService::class);
        self::assertInstanceOf(AdvancedStore::class, $service->store);
        self::assertSame($service->store, $container->get(AdvancedStoreInterface::class));
        self::assertSame(1, $calls);
    }

    public function testLegacyCallableRemainsSupported(): void
    {
        $container = (new Container())->factory(
            'legacy',
            static fn (ContainerInterface $c): object => new stdClass()
        );

        self::assertSame($container->get('legacy'), $container->get('legacy'));
    }

    public function testClassFactoryCanUseContainerDependencies(): void
    {
        $instance = new stdClass();
        $container = (new Container())
            ->set('dependency', $instance)
            ->factory('built', new DependentAdvancedFactory());

        self::assertSame($instance, $container->get('built'));
    }

    public function testFactoryDefinitionReplacesSetAndBind(): void
    {
        $initial = new AdvancedStore();
        $container = (new Container())
            ->bind(AdvancedStoreInterface::class, AdvancedStore::class)
            ->set(AdvancedStoreInterface::class, $initial)
            ->factory(AdvancedStoreInterface::class, new OtherAdvancedStoreFactory());

        self::assertInstanceOf(OtherAdvancedStore::class, $container->get(AdvancedStoreInterface::class));
    }

    public function testSetAndBindReplaceClassFactory(): void
    {
        $instance = new AdvancedStore();
        $container = (new Container())
            ->factory(AdvancedStoreInterface::class, new OtherAdvancedStoreFactory())
            ->set(AdvancedStoreInterface::class, $instance);

        self::assertSame($instance, $container->get(AdvancedStoreInterface::class));

        $container = (new Container())
            ->factory(AdvancedStoreInterface::class, new OtherAdvancedStoreFactory())
            ->bind(AdvancedStoreInterface::class, AdvancedStore::class);

        self::assertInstanceOf(AdvancedStore::class, $container->get(AdvancedStoreInterface::class));
    }

    public function testClassFactoryMustRespectInterface(): void
    {
        $container = (new Container())->factory(AdvancedStoreInterface::class, new InvalidAdvancedFactory());

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('does not implement');
        $container->get(AdvancedStoreInterface::class);
    }

    public function testClassFactoryErrorPreservesCauseAndAllowsRetry(): void
    {
        $factory = new RetryAdvancedFactory();
        $container = (new Container())->factory('flaky', $factory);

        try {
            $container->get('flaky');
            self::fail('Expected factory to fail on first call');
        } catch (ContainerException $error) {
            self::assertInstanceOf(RuntimeException::class, $error->getPrevious());
        }

        self::assertInstanceOf(stdClass::class, $container->get('flaky'));
        self::assertSame(2, $factory->calls);
    }

    public function testClassFactoryCyclesAreDetected(): void
    {
        $container = (new Container())->factory('cycle', new CyclicAdvancedFactory());
        $this->expectExceptionMessage('Circular dependency: cycle -> cycle');
        $container->get('cycle');
    }

    public function testRegistrySnapshotRestoresAllPreviousDefinitions(): void
    {
        $registry = new FactoryRegistry();
        $original = new DependentAdvancedFactory();
        $registry->register('service', $original);
        $snapshot = $registry->snapshot();
        $registry->register('service', new InvalidAdvancedFactory());
        $registry->register('extra', static fn (): object => new stdClass());
        $registry->restore($snapshot);

        self::assertSame($original, $registry->get('service'));
        self::assertFalse($registry->has('extra'));
    }

    public function testRegistryRejectsUnknownFactory(): void
    {
        $this->expectException(ContainerException::class);
        (new FactoryRegistry())->get('unknown');
    }
}

interface AdvancedStoreInterface {}
final class AdvancedStore implements AdvancedStoreInterface {}
final class OtherAdvancedStore implements AdvancedStoreInterface {}
final class AdvancedStoreService
{
    public function __construct(public AdvancedStoreInterface $store) {}
}
final class AdvancedStoreFactory implements FactoryInterface
{
    public function __construct(private int &$calls) {}
    public function create(ContainerInterface $container): object
    {
        ++$this->calls;
        return new AdvancedStore();
    }
}
final class OtherAdvancedStoreFactory implements FactoryInterface
{
    public function create(ContainerInterface $container): object { return new OtherAdvancedStore(); }
}
final class DependentAdvancedFactory implements FactoryInterface
{
    public function create(ContainerInterface $container): object { return $container->get('dependency'); }
}
final class InvalidAdvancedFactory implements FactoryInterface
{
    public function create(ContainerInterface $container): object { return new stdClass(); }
}
final class RetryAdvancedFactory implements FactoryInterface
{
    public int $calls = 0;
    public function create(ContainerInterface $container): object
    {
        if (++$this->calls === 1) {
            throw new RuntimeException('temporary error');
        }
        return new stdClass();
    }
}
final class CyclicAdvancedFactory implements FactoryInterface
{
    public function create(ContainerInterface $container): object { return $container->get('cycle'); }
}
