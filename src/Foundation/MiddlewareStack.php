<?php

declare(strict_types=1);

namespace Ions\Foundation;

use Ions\Container\Container;
use Ions\Http\Middleware\AuthMiddleware;
use Ions\Http\Middleware\CorsMiddleware;
use Ions\Http\Middleware\CsrfMiddleware;
use Ions\Http\Middleware\MiddlewareInterface;
use Ions\Http\Middleware\SecurityHeadersMiddleware;
use Ions\Http\Middleware\StartSessionMiddleware;
use Ions\Http\Middleware\TrustedHostMiddleware;

/**
 * Assembles the per-group default middleware stacks and resolves per-route
 * middleware names to instances. Extracted verbatim from Kernel (12.7) — pure
 * move, no behavior change.
 *
 * @internal Collaborator of {@see Kernel}; not part of the public API.
 */
final class MiddlewareStack
{
    /**
     * Return the per-group default middleware stacks.
     *
     * Config may override this via config('app.middleware').
     *
     * @param Container $app The live application container.
     * @return array<string, list<MiddlewareInterface>>
     */
    public static function defaults(Container $app): array
    {
        // Prefer the container-bound jwt (registered by AuthProvider); fall back to
        // direct construction so auth works even when AuthProvider is not in the list.
        $jwt = $app->has('jwt') ? $app->get('jwt') : JwtFactory::build($app);
        /** @var \Ions\Security\Jwt|null $jwt */

        $userProvider = $app->has('user_provider') ? $app->get('user_provider') : null;
        /** @var \Ions\Auth\Contracts\UserProvider|null $userProvider */

        $web = [
            new TrustedHostMiddleware((array) config('app.trusted_hosts', [])),
            new SecurityHeadersMiddleware(),
            new CorsMiddleware((array) config('app.cors', [])),
        ];
        // Start the session early (before CSRF) so CSRF and downstream code share it.
        if ($app->has('session')) {
            /** @var \Ions\Session\SessionManager $session */
            $session = $app->get('session');
            $web[] = new StartSessionMiddleware($session);
        }
        if (config('app.csrf.enabled', true) && $app->has('csrf')) {
            /** @var \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrfManager */
            $csrfManager = $app->get('csrf');
            $web[] = new CsrfMiddleware($csrfManager);
        }

        // Debug toolbar (10.6): attached only when APP_DEBUG is truthy at
        // stack-build time, so production stacks never even construct it.
        // (config('app.debug_toolbar') is the in-debug escape hatch, checked
        // per response by the middleware itself.)
        if (env('APP_DEBUG', false)) {
            $web[] = new \Ions\Http\Middleware\DebugToolbarMiddleware();
        }

        return [
            'web' => $web,
            'api' => [
                new TrustedHostMiddleware((array) config('app.trusted_hosts', [])),
                new SecurityHeadersMiddleware(),
                new CorsMiddleware((array) config('app.cors', [])),
                new AuthMiddleware($jwt, $userProvider, (array) config('app.auth.public_paths', [])),
            ],
        ];
    }

    /**
     * Resolve a PER-ROUTE middleware name (FQCN or alias) to a MiddlewareInterface
     * instance.
     *
     * Resolution order:
     *   1. If $name exists in config('app.middleware_aliases'), use the mapped class-string.
     *   2. Otherwise treat $name itself as a class-string.
     *   3. Instantiate via the container; verify it implements MiddlewareInterface.
     *
     * Failure policy — FAIL CLOSED, in both debug and production:
     *   Per-route middleware is attached explicitly (->middleware([...]) or the
     *   '_middleware' route option) and is almost always a security gate
     *   ('signed', 'throttle', auth). Silently dropping an unresolvable entry
     *   would serve the route UNPROTECTED — an unsigned request would get a 200.
     *   So an unresolvable name always throws InvalidArgumentException, which
     *   Kernel::handle() routes through the ExceptionHandler as a 500 (generic
     *   body in production, full detail in debug). The cause is additionally
     *   logged in production since the generic error page hides it.
     *
     *   Note on group stacks: the per-group middleware stacks
     *   (config('app.middleware') / defaults()) are arrays of
     *   already-constructed MiddlewareInterface instances and never pass
     *   through this resolver — there is no name-resolution step to fail for
     *   them, so this policy governs per-route middleware only.
     *
     * @param Container $app  The live application container.
     * @param string    $name FQCN or alias string.
     * @return MiddlewareInterface
     * @throws \InvalidArgumentException when the middleware cannot be resolved.
     */
    public static function resolve(Container $app, string $name): MiddlewareInterface
    {
        /** @var array<string,string> $aliases */
        $aliases = (array) config('app.middleware_aliases', []);
        $class = $aliases[$name] ?? $name;

        $unresolvable = static function (string $reason) use ($name): never {
            $message = "Middleware '{$name}' could not be resolved: {$reason}. "
                . "Check 'app.middleware_aliases' or verify the class exists and implements MiddlewareInterface.";

            if (!env('APP_DEBUG', false)) {
                // Production renders a generic 500 page — surface the cause in
                // the logs so operators can see why the route is failing.
                try {
                    \Ions\Bundles\Logs::create('app.log')->error($message);
                } catch (\Throwable) {
                    // Ignore logging failures — never let them mask the original issue.
                }
            }

            throw new \InvalidArgumentException($message);
        };

        if (!class_exists($class)) {
            $unresolvable("class '{$class}' does not exist");
        }

        try {
            $instance = $app->make($class);
        } catch (\Throwable $e) {
            $unresolvable("container could not instantiate '{$class}': " . $e->getMessage());
        }

        if (!($instance instanceof MiddlewareInterface)) {
            $unresolvable("'{$class}' does not implement MiddlewareInterface");
        }

        return $instance;
    }
}
