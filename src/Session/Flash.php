<?php

declare(strict_types=1);

namespace Ions\Session;

use Ions\Foundation\Kernel;
use Ions\Support\Request;
use Throwable;

/**
 * Session-flash plumbing for the web form flow (Phase 10.3).
 *
 * Mechanism (design decision): Symfony's FlashBag, via the SessionManager's
 * existing flash()/getFlash() API — it works identically on the array/mock
 * storage used in tests and the native storage used in production, so no
 * hand-rolled aging is needed. FlashBag semantics are consume-on-read: a
 * value written during request N survives until it is first read (normally
 * the very next request's render) and is gone afterwards.
 *
 * Reads are memoized on the CURRENT request's attribute bag so a template can
 * call errors()/old()/flash($key) any number of times within one render and
 * always see the same value. The memo dies with the request object, so the
 * next Kernel::handle() (fresh Request) consumes fresh flash data.
 *
 * All access is best-effort: with no booted container / 'session' binding
 * (isolated scripts, partial test boots) writes are dropped and reads return
 * null — flash must never take a request down.
 *
 * @internal Used by RedirectResponse, the flash()/old()/errors() helpers and
 *           the ExceptionHandler's web validation branch.
 */
final class Flash
{
    /** Reserved key: array<string, list<string>> validation messages. */
    public const ERRORS = '_ions_errors';

    /** Reserved key: array<string, mixed> of flashed request input. */
    public const OLD_INPUT = '_ions_old_input';

    /** Request-attribute prefix for per-request consume memoization. */
    private const CONSUMED_PREFIX = '_ions_flash_consumed.';

    /**
     * Flash a value for the next request (written immediately).
     */
    public static function put(string $key, mixed $value): void
    {
        $manager = self::manager();
        if ($manager === null) {
            return;
        }

        $manager->flash($key, $value);

        // A read in THIS request may already have memoized the (older) value;
        // drop the memo so subsequent reads observe what was just written.
        self::currentRequest()?->attributes->remove(self::CONSUMED_PREFIX . $key);
    }

    /**
     * Read (and consume) the flashed value for a key — null when nothing was
     * flashed. When several values were flashed under one key, the last wins.
     */
    public static function consume(string $key): mixed
    {
        $request = self::currentRequest();
        $attribute = self::CONSUMED_PREFIX . $key;

        if ($request !== null && $request->attributes->has($attribute)) {
            return $request->attributes->get($attribute);
        }

        $manager = self::manager();
        $values = $manager === null ? [] : $manager->getFlash($key);
        $value = $values === [] ? null : $values[array_key_last($values)];

        $request?->attributes->set($attribute, $value);

        return $value;
    }

    private static function manager(): ?SessionManager
    {
        try {
            $app = Kernel::app();
            if ($app->bound('session')) {
                $manager = $app->get('session');

                return $manager instanceof SessionManager ? $manager : null;
            }
        } catch (Throwable) {
            // Container not booted — flash unavailable.
        }

        return null;
    }

    private static function currentRequest(): ?Request
    {
        try {
            return Kernel::request();
        } catch (Throwable) {
            return null;
        }
    }
}
