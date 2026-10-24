<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Tests;

use Mithridatem\Autowiring\Attribute\Inject;
use Mithridatem\Autowiring\Container;
use Mithridatem\Autowiring\Exception\ContainerException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class InjectTest extends TestCase
{
    public function testNamedServiceOverridesDefaultBindingAndIsShared(): void
    {
        $default = new InjectLogger();
        $selected = new InjectLogger();
        $container = (new Container())->set(InjectLoggerInterface::class, $default)->set('logger.export', $selected);
        self::assertSame($selected, $container->get(InjectConsumer::class)->logger);
        self::assertSame($default, $container->get(InjectLoggerInterface::class));
        self::assertSame($selected, $container->get('logger.export'));
    }

    public function testConfigureOverridesAttribute(): void
    {
        $logger = new InjectLogger();
        $container = (new Container())->configure(InjectConsumer::class, ['logger' => $logger]);
        self::assertSame($logger, $container->get(InjectConsumer::class)->logger);
    }

    public function testClassIdentifierIsAutowiredAndInheritedConstructorWorks(): void
    {
        $container = new Container();
        self::assertSame($container->get(InjectLogger::class), $container->get(InjectChild::class)->logger);
    }

    public function testMissingAttributeServiceDoesNotFallBackToDefault(): void
    {
        $container = (new Container())->set(InjectLoggerInterface::class, new InjectLogger());
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('logger.export');
        $container->get(InjectConsumer::class);
    }

    public function testNamedServiceMustMatchParameterType(): void
    {
        $this->expectExceptionMessage('must implement or extend ' . InjectLoggerInterface::class);
        (new Container())->set('logger.export', new stdClass())->get(InjectConsumer::class);
    }

    public function testRepeatedAttributesAreRejected(): void
    {
        $this->expectExceptionMessage('Only one Inject');
        (new Container())->get(RepeatedInject::class);
    }

    public function testScalarInjectionIsRejected(): void
    {
        $this->expectExceptionMessage('Use configure() for values');
        (new Container())->get(ScalarInject::class);
    }

    public function testEmptyAttributeIdentifierIsRejected(): void
    {
        $this->expectExceptionMessage('identifier must not be empty');
        (new Container())->get(EmptyInject::class);
    }

    public function testAttributeCyclesAreDetected(): void
    {
        $this->expectExceptionMessage('Circular dependency');
        (new Container())->factory('cycle', fn ($c) => $c->get(InjectCycle::class))->get(InjectCycle::class);
    }
}

interface InjectLoggerInterface {}
final class InjectLogger implements InjectLoggerInterface {}
final class InjectConsumer
{
    public function __construct(#[Inject('logger.export')] public InjectLoggerInterface $logger) {}
}
abstract class InjectParent
{
    public function __construct(#[Inject(InjectLogger::class)] public InjectLoggerInterface $logger) {}
}
final class InjectChild extends InjectParent {}
final class RepeatedInject
{
    public function __construct(#[Inject('one'), Inject('two')] InjectLoggerInterface $logger) {}
}
final class ScalarInject
{
    public function __construct(#[Inject('path')] string $path) {}
}
final class EmptyInject
{
    public function __construct(#[Inject('')] InjectLoggerInterface $logger) {}
}
final class InjectCycle
{
    public function __construct(#[Inject('cycle')] public self $value) {}
}
