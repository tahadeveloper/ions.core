<?php

use Ions\Bundles\Path;

test('Path::config() without setBasePath resolves via the original 5-levels-up fallback', function () {
    // Ensure no injected base path is active.
    Path::resetBasePath();

    // Independently compute the expected root using the same 5-up depth that
    // $environmentPath uses: Path lives in src/Bundles, so 5 ".." segments
    // from __DIR__ (which is src/Bundles when running as the package itself)
    // reach the vendor-parent project root.  In this repo the file lives at
    // <repo>/src/Bundles/Path.php, so 5 levels up is <repo>/../../../../..
    // from that directory — which resolves to the repo root itself when the
    // package is used standalone (i.e. the repo IS the host project).
    $bundlesDir = realpath(__DIR__ . '/../../src/Bundles');
    $expectedRoot = realpath(
        $bundlesDir
        . DIRECTORY_SEPARATOR . '..'  // src/
        . DIRECTORY_SEPARATOR . '..'  // <repo root>
        . DIRECTORY_SEPARATOR . '..'  // parent-of-repo  (would be vendor/ionzile/core when installed)
        . DIRECTORY_SEPARATOR . '..'  // vendor/ionzile/
        . DIRECTORY_SEPARATOR . '..'  // vendor/
    );

    $actualConfig = Path::config('');

    // The returned string must start with the expected root path.
    expect($actualConfig)->toStartWith($expectedRoot . DIRECTORY_SEPARATOR);
    // And must contain the config sub-directory.
    expect($actualConfig)->toContain(DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR);
});
