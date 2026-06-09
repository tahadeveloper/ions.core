<?php

namespace Ions\Http\Middleware;

use Ions\Auth\Contracts\UserProvider;
use Ions\Security\Jwt;
use Ions\Security\TokenException;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private ?Jwt $jwt, private ?UserProvider $users = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
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

    private function unauthorized(string $message): Response
    {
        return new JsonResponse(['status' => 'error', 'message' => 'Not authorized!', 'detail' => $message], 401);
    }
}
