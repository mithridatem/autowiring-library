<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Tests\Security;

use Mithridatem\Autowiring\Bridge\Routing\ContainerControllerResolver;
use Mithridatem\Autowiring\Container;
use Mithridatem\Autowiring\Exception\ContainerException;
use Mithridatem\Routing\Auth\ArrayGrantChecker;
use Mithridatem\Routing\Context\NativeRequestContext;
use Mithridatem\Routing\Exception\UnauthorizedException;
use Mithridatem\Routing\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Adversarial integration checks, not a sandbox or HTTP authorization layer.
 * All temporary files and test classes are created under a test-owned directory.
 */
final class DiscoveryRoutingSecurityTest extends TestCase
{
    public function testDeniedRouteDoesNotInstantiateControllerOrResolveItsDependencies(): void
    {
        GuardedController::$constructed = 0;
        $router = new Router(controllerResolver: new ContainerControllerResolver(new Container()));
        $router->setGrantChecker(new ArrayGrantChecker([]));
        $router->mapController(
            'GET',
            '/admin/{id}',
            GuardedController::class,
            'show',
            ['ROLE_ADMIN']
        );

        try {
            $router->dispatch(new NativeRequestContext('/admin/42', 'GET'));
            self::fail('A protected action must not run without its grants.');
        } catch (UnauthorizedException) {
            self::assertSame(0, GuardedController::$constructed);
        }
    }

    public function testUrlParameterIsNotUsedAsAContainerServiceIdentifier(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $controller = new GuardedController();
        $container->expects(self::once())
            ->method('get')
            ->with(GuardedController::class)
            ->willReturn($controller);

        $router = new Router(controllerResolver: new ContainerControllerResolver($container));
        $router->mapController('GET', '/items/{id}', GuardedController::class, 'show');

        // An arbitrary class-looking string remains a handler argument only.
        self::assertSame('item:Untrusted\\Service', $router->dispatch(
            new NativeRequestContext('/items/Untrusted%5CService', 'GET')
        ) === 'item:Untrusted%5CService'
            ? 'item:Untrusted\\Service'
            : 'unexpected');
    }

    public function testDiscoveryAutoloadsCandidateButDoesNotConstructIt(): void
    {
        $root = sys_get_temp_dir() . '/autowiring-discovery-security-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root));
        $namespace = 'SecurityDiscovery\\N' . bin2hex(random_bytes(6));
        $contract = $namespace . '\\Contract';
        $implementation = $namespace . '\\Candidate';
        file_put_contents($root . '/Contract.php', '<?php namespace ' . $namespace . '; interface Contract {}');
        file_put_contents($root . '/Candidate.php', '<?php namespace ' . $namespace . '; final class Candidate implements Contract { public static int $constructed = 0; public function __construct() { ++self::$constructed; } }');
        $autoloadCalls = 0;
        $loader = static function (string $class) use ($root, $namespace, &$autoloadCalls): void {
            if (!str_starts_with($class, $namespace . '\\')) {
                return;
            }
            ++$autoloadCalls;
            $file = $root . '/' . substr($class, strlen($namespace) + 1) . '.php';
            if (is_file($file)) {
                require $file;
            }
        };
        spl_autoload_register($loader);
        try {
            $container = (new Container())->discover($root, $namespace);
            self::assertGreaterThan(0, $autoloadCalls);
            self::assertSame(0, $implementation::$constructed);
            self::assertTrue($container->has($contract));
            self::assertInstanceOf($implementation, $container->get($contract));
            self::assertSame(1, $implementation::$constructed);
        } finally {
            spl_autoload_unregister($loader);
            @unlink($root . '/Candidate.php');
            @unlink($root . '/Contract.php');
            @rmdir($root);
        }
    }

    public function testDiscoveryRejectsAutoloaderPointingAtAnotherPhpFile(): void
    {
        $root = sys_get_temp_dir() . '/autowiring-discovery-security-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root));
        $namespace = 'SecurityDiscovery\\N' . bin2hex(random_bytes(6));
        file_put_contents($root . '/Candidate.php', '<?php // nominal discovery path');
        file_put_contents($root . '/alternate.php', '<?php namespace ' . $namespace . '; final class Candidate {}');
        $loader = static function (string $class) use ($root, $namespace): void {
            if ($class === $namespace . '\\Candidate') {
                require $root . '/alternate.php';
            }
        };
        spl_autoload_register($loader);
        try {
            $container = new Container();
            try {
                $container->discover($root, $namespace);
                self::fail('An autoload path mismatch must be rejected.');
            } catch (ContainerException $error) {
                self::assertStringContainsString('PSR-4 discovery path mismatch', $error->getMessage());
            }
        } finally {
            spl_autoload_unregister($loader);
            @unlink($root . '/Candidate.php');
            @unlink($root . '/alternate.php');
            @rmdir($root);
        }
    }

    public function testDiscoveryDoesNotFollowSymlinkedPhpFilesOutsideRoot(): void
    {
        $base = sys_get_temp_dir() . '/autowiring-discovery-security-' . bin2hex(random_bytes(8));
        $root = $base . '/root';
        self::assertTrue(mkdir($base));
        self::assertTrue(mkdir($root));
        file_put_contents($base . '/outside.php', '<?php // outside the discovery directory');
        try {
            if (!@symlink($base . '/outside.php', $root . '/Candidate.php')) {
                self::markTestSkipped('Symlinks are unavailable on this platform.');
            }
            $container = (new Container())->discover($root, 'SecurityDiscovery\\NotLoaded');
            self::assertFalse($container->has('SecurityDiscovery\\NotLoaded\\Candidate'));
        } finally {
            @unlink($root . '/Candidate.php');
            @unlink($base . '/outside.php');
            @rmdir($root);
            @rmdir($base);
        }
    }
}

final class GuardedController
{
    public static int $constructed = 0;

    public function __construct()
    {
        ++self::$constructed;
    }

    public function show(string $id): string
    {
        return 'item:' . $id;
    }
}
