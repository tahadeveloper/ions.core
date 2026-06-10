<?php

declare(strict_types=1);

namespace Ions\Http\Middleware;

use Ions\Auth\Contracts\UserProvider;
use Ions\Security\Jwt;
use Ions\Security\TokenException;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $publicPaths Paths that bypass authentication (e.g. the
     *                                  login/refresh/forgot/reset endpoints, which
     *                                  authenticate rather than require a prior
     *                                  token). Each entry matches either the exact
     *                                  path or a full path-segment subtree beneath
     *                                  it (i.e. the entry must be followed by '/'
     *                                  or be an exact match). A bare string prefix
     *                                  that shares only a character prefix with a
     *                                  protected route does NOT match.
     */
    public function __construct(
        private ?Jwt $jwt,
        private ?UserProvider $users = null,
        private array $publicPaths = [],
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        // Public endpoints (login, refresh, password reset, ...) are exempt:
        // they establish a session rather than depend on one. This does not
        // weaken the 401 semantics for protected routes — only the explicitly
        // allowlisted prefixes bypass token verification.
        if ($this->isPublic($request->getPathInfo())) {
            return $next($request);
        }

        if ($this->jwt === null) {
            return $this->unauthorized('No signing key configured');
        }
        $header = (string) $request->headers->get('Authorization');
        if ($header === '') {
            return $this->unauthorized('Missing Authorization header');
        }
        $parts = explode(' ', $header, 2);
        if (count($parts) !== 2 || strtolower($parts[0]) !== 'bearer') {
            return $this->unauthorized('Expected a Bearer token');
        }
        try {
            $claims = $this->jwt->verify($parts[1]);
        } catch (TokenException) {
            return $this->unauthorized('Invalid or expired token');
        }

        // Always attach the user id (BC).
        $request->attributes->set('auth_user_id', $claims->userId);

        // If a UserProvider is configured, resolve and validate the user object.
        if ($this->users !== null) {
            $user = $this->users->retrieveById($claims->userId);
            if ($user === null) {
                return $this->unauthorized('User no longer exists');
            }
            $request->attributes->set('auth_user', $user);
        }

        return $next($request);
    }

    private function isPublic(string $path): bool
    {
        foreach ($this->publicPaths as $prefix) {
            if ($prefix === '') {
                continue;
            }
            $prefix = rtrim($prefix, '/');
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function unauthorized(string $message): Response
    {
        return new JsonResponse(['status' => 'error', 'message' => 'Not authorized!', 'detail' => $message], 401);
    }
}
