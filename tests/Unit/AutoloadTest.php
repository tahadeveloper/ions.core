<?php

test('every core PHP file passes php -l syntax check', function () {
    $dir = realpath(__DIR__ . '/../../src');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    $phpBinary = PHP_BINARY;
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $output = [];
        $exitCode = 0;
        exec(escapeshellarg($phpBinary) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
        expect($exitCode)->toBe(0, "Syntax error in {$path}: " . implode("\n", $output));
    }
});
