<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Tests\Security;

use Mithridatem\Autowiring\Attribute\Inject;
use Mithridatem\Autowiring\Container;
use Mithridatem\Autowiring\Exception\ContainerException;
use Mithridatem\Autowiring\Factory\FactoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class FactoryInjectSecurityTest extends TestCase
{
    public function testClassFactoryReturningWrongTypeIsRejectedAndNotCached(): void
    {
        $calls = 0;
        $container = (new Container())->factory(SecurityContract::class, static function () use (&$calls): object {
            ++$calls;
            return $calls === 1 ? new \stdClass() : new SecurityImplementation();
        });

        try {
            $container->get(SecurityContract::class);
            self::fail('The incompatible factory result should be rejected.');
        } catch (ContainerException $error) {
            self::assertStringContainsString('does not implement or extend', $error->getMessage());
        }

        $valid = $container->get(SecurityContract::class);
        self::assertInstanceOf(SecurityImplementation::class, $valid);
        self::assertSame($valid, $container->get(SecurityContract::class));
        self::assertSame(2, $calls);
    }

    public function testFactoryObjectReceivesPsrContainerAndIsCalledOnce(): void
    {
        $factory = new SecurityFactory();
        $container = (new Container())->factory(SecurityContract::class, $factory);

        $service = $container->get(SecurityContract::class);

        self::assertInstanceOf(SecurityImplementation::class, $service);
        self::assertSame($service, $container->get(SecurityContract::class));
        self::assertSame(1, $factory->calls);
        self::assertSame($container, $factory->received);
    }

    public function testReplacementPriorityIgnoresPreviousFactory(): void
    {
        $calls = 0;
        $instance = new SecurityImplementation();
        $container = (new Container())
            ->factory(SecurityContract::class, static function () use (&$calls): object {
                ++$calls;
                return new SecurityImplementation();
            })
            ->set(SecurityContract::class, $instance);

        self::assertSame($instance, $container->get(SecurityContract::class));
        self::assertSame(0, $calls);
    }

    public function testFactoryIndirectCycleIsRejectedAndResolutionStackIsCleaned(): void
    {
        $container = (new Container())
            ->factory('a', static fn (ContainerInterface $c): object => $c->get('b'))
            ->factory('b', static fn (ContainerInterface $c): object => $c->get('a'))
            ->factory('unrelated', static fn (): object => new SecurityImplementation());

        try {
            $container->get('a');
            self::fail('Expected a circular dependency exception.');
        } catch (ContainerException $error) {
            self::assertStringContainsString('Circular dependency', $error->getMessage());
        }

        self::assertInstanceOf(SecurityImplementation::class, $container->get('unrelated'));
    }

    public function testInjectCannotSilentlyFallbackToBoundInterfaceWhenItsNamedFactoryFails(): void
    {
        $fallback = new SecurityImplementation();
        $calls = 0;
        $container = (new Container())
            ->set(SecurityContract::class, $fallback)
            ->factory('service.selected', static function () use (&$calls): object {
                ++$calls;
                throw new \RuntimeException('selected service failure');
            });

        try {
            $container->get(SelectedConsumer::class);
            self::fail('The Inject attribute must not silently fall back.');
        } catch (ContainerException $error) {
            self::assertStringContainsString('selected service failure', $error->getMessage());
        }

        self::assertSame(1, $calls);
        self::assertSame($fallback, $container->get(SecurityContract::class));
    }

    public function testInjectRejectsIncompatibleFactoryResultEvenWhenNamedIdIsFreeForm(): void
    {
        $container = (new Container())->factory('service.selected', static fn (): object => new \stdClass());

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('must implement or extend ' . SecurityContract::class);
        $container->get(SelectedConsumer::class);
    }

    public function testConfigureOverridesInjectWithoutCallingItsFactory(): void
    {
        $calls = 0;
        $selected = new SecurityImplementation();
        $container = (new Container())
            ->factory('service.selected', static function () use (&$calls): object {
                ++$calls;
                return new SecurityImplementation();
            })
            ->configure(SelectedConsumer::class, ['service' => $selected]);

        self::assertSame($selected, $container->get(SelectedConsumer::class)->service);
        self::assertSame(0, $calls);
    }

    public function testConfigurationTypeErrorDoesNotExecuteSelectedFactory(): void
    {
        $calls = 0;
        $container = (new Container())
            ->factory('service.selected', static function () use (&$calls): object {
                ++$calls;
                return new SecurityImplementation();
            })
            ->configure(SelectedConsumer::class, ['service' => new \stdClass()]);

        $this->expectException(ContainerException::class);
        try {
            $container->get(SelectedConsumer::class);
        } finally {
            self::assertSame(0, $calls);
        }
    }
}

interface SecurityContract {}

final class SecurityImplementation implements SecurityContract {}

final class SelectedConsumer
{
    public function __construct(
        #[Inject('service.selected')]
        public SecurityContract $service
    ) {}
}

final class SecurityFactory implements FactoryInterface
{
    public int $calls = 0;
    public ?ContainerInterface $received = null;

    public function create(ContainerInterface $container): object
    {
        ++$this->calls;
        $this->received = $container;
        return new SecurityImplementation();
    }
}
