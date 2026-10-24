<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Discovery;

use Mithridatem\Autowiring\Exception\ContainerException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/** Discover autoloadable PSR-4 implementations without constructing services. */
final class ServiceDiscovery
{
    /** @return array<class-string, list<class-string>> */
    public function discover(string $directory, string $namespace): array
    {
        $root = realpath($directory);
        $namespace = trim($namespace, '\\');
        if ($root === false || !is_dir($root) || !is_readable($root)) {
            throw new ContainerException("Discovery directory is not readable: $directory");
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $namespace)) {
            throw new ContainerException("Invalid discovery namespace: $namespace");
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            // Do not follow symlinked PHP files outside the configured scope.
            if ($file->isFile() && !$file->isLink() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);

        $implementations = [];
        foreach ($files as $file) {
            $relative = substr($file, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1, -4);
            $class = $namespace . '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            // The application's autoloader owns class loading; never require files directly.
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if (realpath($reflection->getFileName() ?: '') !== realpath($file)) {
                throw new ContainerException("PSR-4 discovery path mismatch: $class ($file)");
            }
            if (!$reflection->isInstantiable()) {
                continue;
            }
            foreach ($reflection->getInterfaceNames() as $interface) {
                $implementations[$interface][] = $class;
            }
        }

        return $implementations;
    }
}
