<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Tests;

use Mithridatem\Autowiring\Container;
use Mithridatem\Autowiring\Exception\ContainerException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigurationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/autowiring-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    private function file(string $code, string $name = 'services.php'): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, '<?php ' . $code);
        return $path;
    }

    public function testLoadRegistersFactoriesBindingsAndParametersLazily(): void
    {
        $path = $this->file(<<<'PHP'
use Mithridatem\Autowiring\Container;
use Mithridatem\Autowiring\Tests\ConfigValue;
use Mithridatem\Autowiring\Tests\ConfigInterface;
return static function (Container $c): void {
    $c->configure(ConfigValue::class, ['path' => '/configured']);
    $c->bind(ConfigInterface::class, ConfigValue::class);
    $c->factory('named', fn ($c) => $c->get(ConfigInterface::class));
};
PHP);
        $container = new Container();
        self::assertSame($container, $container->load($path));
        self::assertSame('/configured', $container->get('named')->path);
        self::assertSame($container->get(ConfigValue::class), $container->get('named'));
    }

    public function testNestedImportsAndRepeatedLoadBeforeResolution(): void
    {
        $this->file("return static function (\$c): void { \$c->set('loaded', new stdClass()); };", 'child.php');
        $path = $this->file("return static function (\$c): void { \$c->load(__DIR__ . '/child.php'); };");
        $container = (new Container())->load($path)->load($path);
        self::assertInstanceOf(\stdClass::class, $container->get('loaded'));
    }

    public function testInvalidConfigurationResultIsRejected(): void
    {
        $this->expectExceptionMessage('must return a Closure');
        (new Container())->load($this->file('return [];'));
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectExceptionMessage('not readable');
        (new Container())->load($this->directory . '/missing.php');
    }

    public function testDirectoryIsRejected(): void
    {
        $this->expectExceptionMessage('not readable');
        (new Container())->load($this->directory);
    }

    public function testFailedConfigurationRestoresDefinitionsAndPreservesCause(): void
    {
        $original = new \stdClass();
        $container = (new Container())->set('value', $original);
        $path = $this->file("return static function (\$c): void { \$c->set('value', new stdClass()); \$c->set('partial', new stdClass()); throw new RuntimeException('failure'); };");
        try {
            $container->load($path);
            self::fail('Expected exception');
        } catch (ContainerException $error) {
            self::assertInstanceOf(RuntimeException::class, $error->getPrevious());
            self::assertStringContainsString($path, $error->getMessage());
        }
        // A failed load neither leaves definitions behind nor freezes configuration.
        $container->set('after', new \stdClass());
        self::assertFalse($container->has('partial'));
        self::assertSame($original, $container->get('value'));
    }

    public function testParseErrorIsWrappedAndLoadingStateIsCleaned(): void
    {
        $container = new Container();
        $path = $this->file('return function ( {');
        try {
            $container->load($path);
            self::fail('Expected exception');
        } catch (ContainerException $error) {
            self::assertInstanceOf(\ParseError::class, $error->getPrevious());
        }
        $this->file("return static function (\$c): void { \$c->set('fixed', new stdClass()); };");
        self::assertInstanceOf(\stdClass::class, $container->load($path)->get('fixed'));
    }

    public function testGetInsideConfigurationIsRejectedBeforeLocking(): void
    {
        $container = new Container();
        try {
            $container->load($this->file("return static function (\$c): void { \$c->get('value'); };"));
            self::fail('Expected exception');
        } catch (ContainerException $error) {
            self::assertStringContainsString('register factories', $error->getMessage());
        }
        $value = new \stdClass();
        self::assertSame($value, $container->set('value', $value)->get('value'));
    }

    public function testRecursiveImportsAreRejected(): void
    {
        $path = $this->file("return static function (\$c): void { \$c->load(__FILE__); };");
        $this->expectExceptionMessage('Circular configuration import');
        (new Container())->load($path);
    }

    public function testLoadAfterGetDoesNotExecuteTheFile(): void
    {
        $container = (new Container())->set('value', new \stdClass());
        $container->get('value');
        $path = $this->file("file_put_contents(__DIR__ . '/side-effect', 'unexpected'); return static function (\$c): void {}; ");
        try {
            $container->load($path);
            self::fail('Expected exception');
        } catch (ContainerException $error) {
            self::assertStringContainsString('before the first get()', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->directory . '/side-effect');
    }
}

interface ConfigInterface {}
final class ConfigValue implements ConfigInterface
{
    public function __construct(public string $path) {}
}
