<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Tests\Security;

use Mithridatem\Autowiring\Container;
use Mithridatem\Autowiring\Exception\ContainerException;
use Mithridatem\Autowiring\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * Characterizes intentional trust boundaries; does not redefine public APIs.
 * See docs/SECURITY-BOUNDARIES.md for guidance and remaining review areas.
 */
final class SecurityBoundaryTest extends TestCase
{
    public function testHasDoesNotExecuteAServiceConstructor(): void
    {
        ConstructorProbe::$calls = 0;
        $container = new Container();

        self::assertTrue($container->has(ConstructorProbe::class));
        self::assertSame(0, ConstructorProbe::$calls);
        self::assertSame(0, ConstructorProbe::$calls);
    }

    public function testGetOfAnExplicitlyRequestedConcreteClassRunsItsConstructor(): void
    {
        ConstructorProbe::$calls = 0;
        $container = new Container();

        $first = $container->get(ConstructorProbe::class);

        self::assertSame(1, ConstructorProbe::$calls);
        self::assertSame($first, $container->get(ConstructorProbe::class));
        self::assertSame(1, ConstructorProbe::$calls);
    }

    public function testUnknownServiceIdCannotCreateAnObject(): void
    {
        $container = new Container();
        self::assertFalse($container->has('Untrusted\\NoSuchService'));

        $this->expectException(NotFoundException::class);
        $container->get('Untrusted\\NoSuchService');
    }

    public function testInvalidFactoryResultIsNotCachedAndCanBeRetried(): void
    {
        $attempts = 0;
        $container = (new Container())->factory(ConstructorProbe::class, static function () use (&$attempts): object {
            ++$attempts;
            return $attempts === 1 ? new \stdClass() : new ConstructorProbe();
        });

        try {
            $container->get(ConstructorProbe::class);
            self::fail('A factory returning a wrong service type must fail.');
        } catch (ContainerException $error) {
            self::assertStringContainsString('does not implement', $error->getMessage());
        }

        self::assertInstanceOf(ConstructorProbe::class, $container->get(ConstructorProbe::class));
        self::assertSame(2, $attempts);
    }

    public function testFactoryCannotChangeRegistrationsDuringResolution(): void
    {
        $container = (new Container())->factory('unsafe', static function (Container $c): object {
            $c->set('injected', new \stdClass());
            return new \stdClass();
        });

        try {
            $container->get('unsafe');
            self::fail('Definitions must be frozen during resolution.');
        } catch (ContainerException $error) {
            self::assertStringContainsString('before the first get()', $error->getMessage());
        }

        self::assertFalse($container->has('injected'));
    }

    public function testAConfiguredPhpFileIsExecutedAndMustBeTrusted(): void
    {
        $directory = sys_get_temp_dir() . '/autowiring-security-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $path = $directory . '/services.php';
        $marker = $directory . '/executed';
        try {
            file_put_contents($path, <<<'PHP'
<?php
file_put_contents(__DIR__ . '/executed', 'yes');
return static function (\Mithridatem\Autowiring\Container $c): void {};
PHP
            );
            (new Container())->load($path);
            self::assertSame('yes', file_get_contents($marker));
        } finally {
            @unlink($marker);
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testFailedConfigurationRestoresDefinitionsButCannotUndoPhpSideEffects(): void
    {
        $directory = sys_get_temp_dir() . '/autowiring-security-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $path = $directory . '/services.php';
        $marker = $directory . '/executed';
        $original = new \stdClass();
        $container = (new Container())->set('existing', $original);
        try {
            file_put_contents($path, <<<'PHP'
<?php
return static function (\Mithridatem\Autowiring\Container $c): void {
    $c->set('new', new \stdClass());
    file_put_contents(__DIR__ . '/executed', 'yes');
    throw new \RuntimeException('intentional test failure');
};
PHP
            );
            try {
                $container->load($path);
                self::fail('Failed configuration must be rejected.');
            } catch (ContainerException $error) {
                self::assertInstanceOf(\RuntimeException::class, $error->getPrevious());
            }

            self::assertFalse($container->has('new'));
            self::assertSame('yes', file_get_contents($marker));
            self::assertSame($original, $container->get('existing'));
        } finally {
            @unlink($marker);
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testInstancesAreSharedOnlyWithinTheSameContainer(): void
    {
        ConstructorProbe::$calls = 0;
        $first = new Container();
        $second = new Container();
        self::assertSame($first->get(ConstructorProbe::class), $first->get(ConstructorProbe::class));
        self::assertNotSame($first->get(ConstructorProbe::class), $second->get(ConstructorProbe::class));
        self::assertSame(2, ConstructorProbe::$calls);
    }
}

final class ConstructorProbe
{
    public static int $calls = 0;

    public function __construct()
    {
        ++self::$calls;
    }
}
