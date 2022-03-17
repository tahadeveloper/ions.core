<?php

namespace Ions\Bundles;

use Illuminate\Http\RedirectResponse;
use Ions\Foundation\Kernel;
use JetBrains\PhpStorm\NoReturn;

class Redirect
{

    /**
     * Create a new redirect response to the "home" route.
     *
     * @param int $status
     * @return void
     */
    #[NoReturn] public static function home(int $status = 302): void
    {
        self::to(appUrl(), $status)->send();
    }

    /**
     * Create a new redirect response to the previous location.
     *
     * @param int $status
     * @param array $headers
     * @return RedirectResponse|null
     */
    public static function back(int $status = 302, array $headers = []): ?RedirectResponse
    {

        $path = Kernel::request()->header('referer');
        if (Kernel::request()->header('referer')) {
            return self::createRedirect($path, $status, $headers)->send();
        }
        return null;
    }

    /**
     * Create a new redirect response to the current URI.
     *
     * @param int $status
     * @param array $headers
     * @return void
     */
    #[NoReturn] public static function refresh(int $status = 302, array $headers = []): void
    {
        $path = Kernel::request()->getUri();
         self::to($path, $status, $headers)->send();

    }

    /**
     * Create a new redirect response to the given path.
     *
     * @param string $path
     * @param int $status
     * @param array $headers
     * @return RedirectResponse
     */
    #[NoReturn] protected static function to(string $path, int $status = 302, array $headers = []): RedirectResponse
    {
         return self::createRedirect($path, $status, $headers);
    }

    /**
     * Create a new redirect response to an external URL (no validation).
     *
     * @param string $path
     * @param int $status
     * @param array $headers
     * @return void
     */
    #[NoReturn] public static function away(string $path, int $status = 302, array $headers = []): void
    {
         self::createRedirect($path, $status, $headers)->send();
        exit();
    }

    /**
     * Create a new redirect response to internal (no validation).
     *
     * @param string $path
     * @param int $status
     * @param array $headers
     * @return void
     */
    #[NoReturn] public static function internal(string $path, int $status = 302, array $headers = []):void
    {
        self::createRedirect(appUrl().DIRECTORY_SEPARATOR.$path, $status, $headers)->send();
        exit();
    }

    /**
     * Create a new redirect response.
     *
     * @param string $path
     * @param int $status
     * @param array $headers
     * @return RedirectResponse
     */
    protected static function createRedirect(string $path, int $status, array $headers): RedirectResponse
    {
        return tap(new RedirectResponse($path, $status, $headers), static function ($redirect) {
            $redirect->setRequest(Kernel::request());
        });
    }


}
