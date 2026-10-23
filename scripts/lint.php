<?php

declare(strict_types=1);

$root = dirname(__DIR__);
foreach (['src', 'tests', 'examples', 'scripts'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            passthru(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()), $status);
            if ($status !== 0) {
                exit($status);
            }
        }
    }
}
