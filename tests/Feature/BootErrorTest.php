<?php

/**
 * Verify that boot/config errors are re-thrown when APP_DEBUG is on,
 * rather than silently calling die().
 *
 * APP_DEBUG is forced via putenv (createImmutable won't override an already-set
 * env var, so we set it directly before booting and restore it afterwards).
 */
test('boot re-throws config errors when APP_DEBUG is on (no silent die)', function () {
    // Ensure APP_DEBUG=true for this test regardless of fixture .env immutability
    $originalDebug = getenv('APP_DEBUG');
    putenv('APP_DEBUG=true');
    $_ENV['APP_DEBUG'] = 'true';

    try {
        expect(fn () => bootFixtureKernel(__DIR__ . '/../fixtures/app-badconfig'))
            ->toThrow(\RuntimeException::class);
    } finally {
        // Restore original value to avoid leaking into other tests
        if ($originalDebug === false) {
            putenv('APP_DEBUG');   // unset
            unset($_ENV['APP_DEBUG']);
        } else {
            putenv('APP_DEBUG=' . $originalDebug);
            $_ENV['APP_DEBUG'] = $originalDebug;
        }
    }
});
