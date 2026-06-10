<?php

declare(strict_types=1);

namespace Ions\Support\Concerns;

use Ions\Foundation\Kernel;
use RuntimeException;

/**
 * Shared fake plumbing for the static testing facades
 * ({@see \Ions\Support\Queue}, {@see \Ions\Support\Event},
 * {@see \Ions\Support\Mail}, {@see \Ions\Support\Http}).
 *
 * installFake() swaps a container binding for the recording fake;
 * resolveInstalledFake() hands the assertion passthroughs the installed fake.
 * The resolved() guard is checked first so a missing ::fake() call fails with
 * the message pointing at the facade instead of lazily building the real
 * service (SMTP transport, queue manager, Symfony client, ...) only to
 * discard it.
 */
trait ResolvesFake
{
    /**
     * Bind the fake as the shared container instance for $binding.
     *
     * @template TFake of object
     *
     * @param TFake $fake
     *
     * @return TFake
     */
    private static function installFake(string $binding, object $fake): object
    {
        Kernel::app()->instance($binding, $fake);

        return $fake;
    }

    /**
     * The fake currently bound as $binding, or a hard failure pointing at the
     * missing {facade}::fake() call — without resolving the real service.
     *
     * @template TFake of object
     *
     * @param class-string<TFake> $fakeClass
     *
     * @return TFake
     */
    private static function resolveInstalledFake(string $binding, string $fakeClass, string $facade): object
    {
        $app = Kernel::app();

        // resolved() is checked first so a missing fake fails with the message
        // below instead of lazily building the real service.
        $bound = $app->resolved($binding) ? $app->get($binding) : null;

        if (!$bound instanceof $fakeClass) {
            throw new RuntimeException(sprintf(
                '%1$s assertions require the fake: call %1$s::fake() in your test first.',
                $facade
            ));
        }

        return $bound;
    }
}
