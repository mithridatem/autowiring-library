<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Tests;

use Mithridatem\Autowiring\Container;
use Mithridatem\Autowiring\Discovery\ServiceDiscovery;
use Mithridatem\Autowiring\Exception\ContainerException;
use PHPUnit\Framework\TestCase;

final class DiscoveryTest extends TestCase
{
    private string $root;
    private string $namespace;
    private \Closure $autoload;

    protected function setUp(): void
    {
        $id = bin2hex(random_bytes(8));
        $this->root = sys_get_temp_dir() . '/discovery-' . $id;
        $this->namespace = 'DiscoveryFixture\\F' . $id;
        mkdir($this->root);
        $this->autoload = function (string $class): void {
            $prefix = $this->namespace . '\\';
            if (str_starts_with($class, $prefix)) {
                $file = $this->root . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($this->autoload);
        $this->file('Contract', 'interface Contract {}');
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoload);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->root);
    }

    private function fqcn(string $name): string { return $this->namespace . '\\' . $name; }

    private function file(string $relative, string $body): string
    {
        $path = $this->root . '/' . str_replace('\\', '/', $relative) . '.php';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $separator = strrpos($relative, '\\');
        $namespace = $this->namespace . ($separator === false ? '' : '\\' . substr($relative, 0, $separator));
        file_put_contents($path, '<?php namespace ' . $namespace . '; ' . $body);
        return $path;
    }

    private function implementation(string $name = 'Store'): string
    {
        $class = substr($name, (strrpos($name, '\\') ?: -1) + 1);
        return $this->file($name, "final class $class implements \\" . $this->fqcn('Contract') . ' { public static int $constructed = 0; public function __construct() { ++self::$constructed; } }');
    }

    public function testUniqueInterfaceResolutionIsLazyAndSharedWithConcreteClass(): void
    {
        $this->implementation();
        $class = $this->fqcn('Store');
        $container = new Container();
        self::assertSame($container, $container->discover($this->root, $this->namespace));
        self::assertSame(0, $class::$constructed);
        self::assertTrue($container->has($this->fqcn('Contract')));
        self::assertSame($container->get($class), $container->get($this->fqcn('Contract')));
        self::assertSame(1, $class::$constructed);
    }

    public function testAmbiguityListsBothCandidatesWithoutConstructingThem(): void
    {
        $this->implementation('First');
        $this->implementation('Second');
        $container = (new Container())->discover($this->root, $this->namespace);
        self::assertTrue($container->has($this->fqcn('Contract')));
        try {
            $container->get($this->fqcn('Contract'));
            self::fail('Expected ambiguity');
        } catch (ContainerException $error) {
            self::assertStringContainsString('Ambiguous implementations', $error->getMessage());
            self::assertStringContainsString($this->fqcn('First'), $error->getMessage());
            self::assertStringContainsString($this->fqcn('Second'), $error->getMessage());
        }
        $first = $this->fqcn('First');
        self::assertSame(0, $first::$constructed);
    }

    public function testManualBindingWinsBeforeAndAfterDiscovery(): void
    {
        $this->implementation('First');
        $this->implementation('Second');
        $contract = $this->fqcn('Contract');
        $first = $this->fqcn('First');
        $before = (new Container())->bind($contract, $first)->discover($this->root, $this->namespace);
        $after = (new Container())->discover($this->root, $this->namespace)->bind($contract, $first);
        self::assertInstanceOf($first, $before->get($contract));
        self::assertInstanceOf($first, $after->get($contract));
    }

    public function testInstancesAndFactoriesOverrideDiscoveredCandidates(): void
    {
        $this->implementation();
        $contract = $this->fqcn('Contract');
        $class = $this->fqcn('Store');
        $instance = new $class();
        self::assertSame($instance, (new Container())->set($contract, $instance)->discover($this->root, $this->namespace)->get($contract));
        self::assertSame($instance, (new Container())->discover($this->root, $this->namespace)->factory($contract, fn () => $instance)->get($contract));
    }

    public function testInjectSelectsAnImplementationOfAnAmbiguousInterface(): void
    {
        $this->implementation('First');
        $this->implementation('Second');
        $this->file('Consumer', 'final class Consumer { public function __construct(#[\\Mithridatem\\Autowiring\\Attribute\\Inject(First::class)] public Contract $store) {} }');
        $container = (new Container())->discover($this->root, $this->namespace);
        self::assertInstanceOf($this->fqcn('First'), $container->get($this->fqcn('Consumer'))->store);
    }

    public function testSubdirectoriesAndRepeatedDiscoveryAreSupported(): void
    {
        $this->implementation('Nested\\Store');
        $container = (new Container())->discover($this->root, $this->namespace)->discover($this->root . '/Nested', $this->namespace . '\\Nested');
        self::assertInstanceOf($this->fqcn('Nested\\Store'), $container->get($this->fqcn('Contract')));
    }

    public function testCandidatesFromMultipleScopesAreCombined(): void
    {
        $this->implementation('One\\Store');
        $this->implementation('Two\\Store');
        $container = (new Container())->discover($this->root . '/One', $this->namespace . '\\One')->discover($this->root . '/Two', $this->namespace . '\\Two');
        $this->expectExceptionMessage('Ambiguous implementations');
        $container->get($this->fqcn('Contract'));
    }

    public function testAbstractClassesTraitsInterfacesAndEnumsAreNotCandidates(): void
    {
        $this->file('AbstractStore', 'abstract class AbstractStore implements Contract {}');
        $this->file('StoreTrait', 'trait StoreTrait {}');
        $this->file('Status', 'enum Status implements Contract { case Open; }');
        $this->file('ChildContract', 'interface ChildContract extends Contract {}');
        self::assertSame([], (new ServiceDiscovery())->discover($this->root, $this->namespace));
        self::assertFalse((new Container())->discover($this->root, $this->namespace)->has($this->fqcn('Contract')));
    }

    public function testInheritedInterfacesAreDiscovered(): void
    {
        $this->file('BaseStore', 'abstract class BaseStore implements Contract {}');
        $this->file('Store', 'final class Store extends BaseStore {}');
        self::assertInstanceOf($this->fqcn('Store'), (new Container())->discover($this->root, $this->namespace)->get($this->fqcn('Contract')));
    }

    public function testParentAndChildInterfacesShareTheSameImplementation(): void
    {
        $this->file('ChildContract', 'interface ChildContract extends Contract {}');
        $this->file('Store', 'final class Store implements ChildContract {}');
        $container = (new Container())->discover($this->root, $this->namespace);
        self::assertSame($container->get($this->fqcn('Contract')), $container->get($this->fqcn('ChildContract')));
    }

    public function testFailedDiscoveryDoesNotPartiallyRegisterCandidates(): void
    {
        $this->implementation('First');
        $this->file('Mismatch', 'require __DIR__ . "/actual.txt";');
        file_put_contents($this->root . '/actual.txt', '<?php namespace ' . $this->namespace . '; final class Mismatch implements Contract {}');
        $container = new Container();
        try {
            $container->discover($this->root, $this->namespace);
            self::fail('Expected mismatch');
        } catch (ContainerException $error) {
            self::assertStringContainsString('PSR-4 discovery path mismatch', $error->getMessage());
        }
        self::assertFalse($container->has($this->fqcn('Contract')));
    }

    public function testSymlinkedPhpFilesAreNotLoaded(): void
    {
        file_put_contents($this->root . '/actual.txt', '<?php namespace ' . $this->namespace . '; final class Linked implements Contract {}');
        if (!@symlink($this->root . '/actual.txt', $this->root . '/Linked.php')) {
            self::markTestSkipped('Symlinks unavailable on this platform.');
        }
        self::assertSame([], (new ServiceDiscovery())->discover($this->root, $this->namespace));
        self::assertFalse(class_exists($this->fqcn('Linked'), false));
    }

    public function testConstructorRequirementsAreCheckedAtResolutionTime(): void
    {
        $this->file('Store', 'final class Store implements Contract { public function __construct(string $path) {} }');
        $container = (new Container())->discover($this->root, $this->namespace);
        self::assertTrue($container->has($this->fqcn('Contract')));
        $this->expectExceptionMessage('Unresolvable parameter');
        $container->get($this->fqcn('Contract'));
    }

    public function testNonAutoloadableFilesAreIgnored(): void
    {
        $this->implementation();
        spl_autoload_unregister($this->autoload);
        self::assertSame([], (new ServiceDiscovery())->discover($this->root, $this->namespace));
    }

    public function testUnknownDirectoryAndInvalidNamespaceFailClearly(): void
    {
        try {
            (new Container())->discover($this->root . '/missing', $this->namespace);
            self::fail('Expected directory error');
        } catch (ContainerException $error) {
            self::assertStringContainsString('not readable', $error->getMessage());
        }
        $this->expectExceptionMessage('Invalid discovery namespace');
        (new Container())->discover($this->root, 'Invalid-Namespace');
    }

    public function testDiscoveryAfterResolutionIsRejected(): void
    {
        $container = (new Container())->set('value', new \stdClass());
        $container->get('value');
        $this->expectExceptionMessage('before the first get()');
        $container->discover($this->root, $this->namespace);
    }

    public function testConfigurationCanDiscoverAndRollsBackCandidatesOnFailure(): void
    {
        $this->implementation();
        $path = $this->root . '/services.php';
        $code = '<?php return static function ($c): void { $c->discover(__DIR__, ' . var_export($this->namespace, true) . '); throw new RuntimeException("failure"); };';
        file_put_contents($path, $code);
        $container = new Container();
        try {
            $container->load($path);
            self::fail('Expected load error');
        } catch (ContainerException $error) {
            self::assertStringContainsString('failure', $error->getMessage());
        }
        self::assertFalse($container->has($this->fqcn('Contract')));
        self::assertInstanceOf($this->fqcn('Store'), $container->discover($this->root, $this->namespace)->get($this->fqcn('Contract')));
    }
}
