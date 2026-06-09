<?php

declare(strict_types=1);

namespace Ions\Providers;

use Ions\Container\ServiceProvider;
use Ions\Session\SessionManager;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class SessionProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!$this->container->bound('session')) {
            $this->container->singleton('session', static function (): SessionManager {
                /** @var array<string,mixed> $config */
                $config = (array) config('session', []);

                return new SessionManager($config);
            });
        }

        // A shared RequestStack whose current request carries the framework
        // session. SessionTokenStorage (used by the CSRF manager) reads the
        // session through this stack, so CSRF tokens live in the framework
        // session — one source of truth. StartSessionMiddleware keeps the live
        // request's session in sync, but having a request on the stack here lets
        // token helpers work even outside the middleware pipeline (e.g. CLI).
        if (!$this->container->bound('request_stack')) {
            $container = $this->container;
            $this->container->singleton('request_stack', static function () use ($container): RequestStack {
                $stack = new RequestStack();
                $request = new Request();
                /** @var SessionManager $session */
                $session = $container->get('session');
                $request->setSession($session->getSession());
                $stack->push($request);

                return $stack;
            });
        }
    }
}
