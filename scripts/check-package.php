<?php

declare(strict_types=1);

// Inspect the actual distribution, then prepare a separate consumer application.
// The synthetic version below is local test metadata, never a published release.
$archivePath = realpath($argv[1] ?? '');
if ($archivePath === false || !is_file($archivePath)) {
    throw new RuntimeException('Usage: php scripts/check-package.php path/to/archive.zip');
}

$zip = new ZipArchive();
if ($zip->open($archivePath) !== true) {
    throw new RuntimeException('Cannot open package archive.');
}

$required = ['composer.json', 'LICENSE', 'README.md', 'CHANGELOG.md', 'src/Container.php',
    'src/Exception/ContainerException.php', 'src/Exception/NotFoundException.php',
    'src/Bridge/Routing/ContainerControllerResolver.php'];
foreach ($required as $path) {
    if ($zip->locateName($path) === false) {
        throw new RuntimeException("Missing distribution file: $path");
    }
}

$forbidden = ['.git', '.gitattributes', '.gitignore', '.github', '.build', '.phpunit.cache',
    'tests', 'examples', 'scripts', 'docs', 'vendor', 'phpunit.xml', 'composer.lock'];
for ($index = 0; $index < $zip->numFiles; ++$index) {
    $entry = $zip->getNameIndex($index);
    if (in_array(explode('/', $entry)[0], $forbidden, true)) {
        throw new RuntimeException("Development file in distribution: $entry");
    }
}

$metadata = json_decode($zip->getFromName('composer.json'), true, 512, JSON_THROW_ON_ERROR);
$zip->close();
if (isset($metadata['version']) || $metadata['name'] !== 'mithridatem/autowiring') {
    throw new RuntimeException('Unexpected package name or hardcoded version.');
}

$metadata['version'] = '1.0.0';
$metadata['dist'] = ['type' => 'zip', 'url' => 'file://' . $archivePath];
$consumer = dirname($archivePath) . '/consumer';
if (!is_dir($consumer) && !mkdir($consumer, 0777, true)) {
    throw new RuntimeException('Cannot create consumer directory.');
}
file_put_contents($consumer . '/composer.json', json_encode([
    'name' => 'local/autowiring-consumer',
    'require' => ['mithridatem/autowiring' => '^1.0'],
    'repositories' => [['type' => 'package', 'package' => $metadata]],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

file_put_contents($consumer . '/smoke.php', <<<'PHP'
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mithridatem\Autowiring\Container;
use Psr\Container\ContainerInterface;

final class Dependency {}
final class Service
{
    public function __construct(public Dependency $dependency) {}
}

$container = new Container();
if (!$container instanceof ContainerInterface
    || !$container->get(Service::class)->dependency instanceof Dependency) {
    throw new RuntimeException('Production autoload or autowiring failed.');
}
foreach (['PHPUnit\Framework\TestCase', 'Example\Controller\BookController',
    'Mithridatem\Routing\Router'] as $class) {
    if (class_exists($class)) {
        throw new RuntimeException('Unexpected development dependency: ' . $class);
    }
}
echo "Production installation OK (PSR-11, autowiring, no router or dev dependencies).\n";
PHP
);

echo "Distribution contents OK; consumer prepared at $consumer" . PHP_EOL;
