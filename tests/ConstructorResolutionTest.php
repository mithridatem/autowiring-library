<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Tests;

use Mithridatem\Autowiring\Attribute\Inject;
use Mithridatem\Autowiring\Container;
use Mithridatem\Autowiring\Exception\ContainerException;
use PHPUnit\Framework\TestCase;
use stdClass;

/** Regression tests for the extracted reflection/constructor pipeline. */
final class ConstructorResolutionTest extends TestCase
{
    public function testNestedAutowiringStillSharesServiceInstances(): void
    {
        $container = new Container();
        $consumer = $container->get(ConstructionConsumer::class);
        self::assertSame($container->get(ConstructionDependency::class), $consumer->dependency);
        self::assertSame($consumer, $container->get(ConstructionConsumer::class));
    }

    public function testExplicitNullOverridesDefaultAndAttribute(): void
    {
        $dependency = new ConstructionDependency();
        $container = (new Container())
            ->set('named.dependency', new ConstructionDependency())
            ->configure(ConstructionConsumer::class, ['dependency' => $dependency]);
        self::assertSame($dependency, $container->get(ConstructionConsumer::class)->dependency);

        $configured = (new Container())->configure(ConstructionScalar::class, ['value' => null]);
        self::assertNull($configured->get(ConstructionScalar::class)->value);
    }

    public function testAttributeResolutionUsesNamedService(): void
    {
        $dependency = new ConstructionDependency();
        $container = (new Container())->set('named.dependency', $dependency);
        self::assertSame($dependency, $container->get(ConstructionAttributed::class)->dependency);
    }

    public function testMissingNamedServiceRetainsWrappedException(): void
    {
        try {
            (new Container())->get(ConstructionAttributed::class);
            self::fail('Expected exception');
        } catch (ContainerException $error) {
            self::assertStringContainsString('named.dependency', $error->getMessage());
            self::assertNotNull($error->getPrevious());
        }
    }

    public function testInternalClassesStillRequireRegistration(): void
    {
        $this->expectExceptionMessage('internal class');
        (new Container())->get(stdClass::class);
    }

    public function testConstructorUnionWithoutExplicitConfigurationIsNotGuessed(): void
    {
        $this->expectExceptionMessage('Unresolvable parameter');
        (new Container())->get(ConstructionUnion::class);
    }
}

final class ConstructionDependency {}
final class ConstructionConsumer
{
    public function __construct(public ConstructionDependency $dependency) {}
}
final class ConstructionAttributed
{
    public function __construct(#[Inject('named.dependency')] public ConstructionDependency $dependency) {}
}
final class ConstructionScalar
{
    public function __construct(public ?string $value = 'default') {}
}
final class ConstructionUnion
{
    public function __construct(public int|string $value) {}
}
