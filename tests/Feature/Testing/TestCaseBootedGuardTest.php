<?php

declare(strict_types=1);

use Ions\Testing\TestCase;
use Ions\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| TestCase booted guard
|--------------------------------------------------------------------------
| A host test that overrides setUp() without calling parent::setUp() never
| boots the kernel; any request helper must fail with a pointed message
| instead of a confusing null-container fatal deep inside Kernel::handle().
*/

function bootedGuardCase(): TestCase
{
    return new class ('bootedGuard') extends TestCase {
        protected string $basePath = __DIR__ . '/../../fixtures/app';

        /** Simulates the host override that FORGOT parent::setUp(). */
        protected function setUp(): void
        {
            // intentionally empty
        }

        public function bootProperly(): void
        {
            parent::setUp();
        }

        public function shutdown(): void
        {
            $this->tearDown();
        }

        public function hit(): TestResponse
        {
            return $this->get('/ping');
        }
    };
}

test('call() throws a pointed RuntimeException when parent::setUp() never ran', function () {
    $case = bootedGuardCase();

    expect(fn () => $case->hit())->toThrow(
        RuntimeException::class,
        'Kernel not booted — did you forget parent::setUp() in your setUp() override?'
    );
});

test('the booted flag is set by setUp() and cleared again by tearDown()', function () {
    $case = bootedGuardCase();

    // After a real parent::setUp() the request helpers work…
    $case->bootProperly();
    $case->hit()->assertOk()->assertSee('pong');

    // …and after tearDown() the guard trips again.
    $case->shutdown();
    expect(fn () => $case->hit())->toThrow(RuntimeException::class, 'Kernel not booted');
});
