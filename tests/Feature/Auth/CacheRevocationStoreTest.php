<?php

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Ions\Security\CacheRevocationStore;

test('revocation persists across separate store instances (simulating cross-request)', function () {
    $dir = sys_get_temp_dir() . '/ions_revtest_' . bin2hex(random_bytes(4));
    $make = fn () => new CacheRevocationStore(new Repository(new FileStore(new Filesystem(), $dir)));
    $a = $make();
    $a->revoke('jti-123', 3600);
    $b = $make(); // a *different* instance over the same cache dir = a different request
    expect($b->isRevoked('jti-123'))->toBeTrue()
        ->and($b->isRevoked('jti-other'))->toBeFalse();
    // cleanup (FileStore uses hashed subdirectories)
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $path) {
        $path->isDir() ? @rmdir((string) $path) : @unlink((string) $path);
    }
    @rmdir($dir);
});
