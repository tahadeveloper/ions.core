<?php

declare(strict_types=1);

namespace Ions\Auth\Http;

use Ions\Auth\Contracts\SupportsPasswordReset;
use Ions\Auth\Contracts\UserProvider;
use Ions\Http\Json;
use Ions\Http\RequestInput;
use Ions\Security\Jwt;
use Ions\Security\TokenException;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Framework-provided HTTP auth surface: login / refresh / logout / password-reset.
 *
 * Each action returns a {@see JsonResponse} (via {@see Json}) and depends only on
 * the container-bound `jwt` ({@see Jwt}) and `user_provider` ({@see UserProvider}).
 * Issued access tokens are bound to the authenticated user's id
 * (`Jwt::issue($user->getAuthIdentifier())`), so {@see \Ions\Http\Middleware\AuthMiddleware}
 * resolves the real user — never an application id.
 *
 * Host apps may subclass this, reference it from a closure route, or register
 * their own controller; the example routes live in `routes/api.php`.
 */
class AuthController
{
    private ?Jwt $jwt;
    private ?UserProvider $users;

    public function __construct(?Jwt $jwt = null, ?UserProvider $users = null)
    {
        $this->jwt = $jwt ?? (app()->has('jwt') ? app('jwt') : null);
        $this->users = $users ?? (app()->has('user_provider') ? app('user_provider') : null);
    }

    /**
     * Authenticate by credentials and issue an access + refresh token pair.
     */
    public function login(Request $request): JsonResponse
    {
        if ($this->jwt === null) {
            return Json::error('Auth unavailable', 503);
        }
        if ($this->users === null) {
            return Json::error('No user provider configured', 503);
        }

        /** @var array<string,mixed> $input */
        $input = (array) RequestInput::parse($request);

        $user = $this->users->retrieveByCredentials($input);
        if ($user === null || !$this->users->validateCredentials($user, $input)) {
            return Json::error('Invalid credentials', 401);
        }

        $userId = (string) $user->getAuthIdentifier();

        return Json::ok([
            'access_token'  => $this->jwt->issue($userId),
            'refresh_token' => $this->jwt->issueRefresh($userId),
            'token_type'    => 'Bearer',
        ]);
    }

    /**
     * Exchange a refresh token for a new access token (rotates + revokes the old one).
     */
    public function refresh(Request $request): JsonResponse
    {
        if ($this->jwt === null) {
            return Json::error('Auth unavailable', 503);
        }

        /** @var array<string,mixed> $input */
        $input = (array) RequestInput::parse($request);
        $token = $this->tokenFrom($request, $input, 'refresh_token');

        if ($token === '') {
            return Json::error('Missing refresh token', 401);
        }

        try {
            $accessToken = $this->jwt->refresh($token);
        } catch (TokenException) {
            return Json::error('Invalid or expired refresh token', 401);
        }

        return Json::ok([
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
        ]);
    }

    /**
     * Revoke the presented access token so it can no longer be used.
     */
    public function logout(Request $request): JsonResponse
    {
        if ($this->jwt === null) {
            return Json::error('Auth unavailable', 503);
        }

        /** @var array<string,mixed> $input */
        $input = (array) RequestInput::parse($request);
        $token = $this->tokenFrom($request, $input, 'access_token');

        if ($token !== '') {
            $this->jwt->revoke($token);
        }

        return Json::ok(['message' => 'logged out']);
    }

    /**
     * Issue a password-reset code for the user matching the request credentials.
     *
     * Always responds 200 with a generic message (no user enumeration) when the
     * provider supports reset; 501 when it does not.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $provider = $this->resetProvider();
        if ($provider === null) {
            return Json::error('Password reset not supported by the configured provider', 501);
        }

        /** @var array<string,mixed> $input */
        $input = (array) RequestInput::parse($request);

        // The issued code is delivered out-of-band (e.g. e-mail); it is never
        // returned in the response to avoid leaking it to unauthenticated callers.
        $provider->createResetCode($input);

        return Json::ok(['message' => 'If the account exists, a reset code has been sent']);
    }

    /**
     * Apply a reset code and set the user's new password.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $provider = $this->resetProvider();
        if ($provider === null) {
            return Json::error('Password reset not supported by the configured provider', 501);
        }

        /** @var array<string,mixed> $input */
        $input = (array) RequestInput::parse($request);
        $code = (string) ($input['code'] ?? '');
        $password = (string) ($input['password'] ?? '');

        if ($code === '' || $password === '') {
            return Json::error('A reset code and new password are required', 422);
        }

        if (!$provider->resetPassword($input, $code, $password)) {
            return Json::error('Invalid or expired reset code', 422);
        }

        return Json::ok(['message' => 'password reset']);
    }

    /**
     * Resolve the bound provider only when it supports password reset.
     */
    private function resetProvider(): ?SupportsPasswordReset
    {
        return $this->users instanceof SupportsPasswordReset ? $this->users : null;
    }

    /**
     * Read a token from the request body key, falling back to the Bearer header.
     *
     * @param array<string,mixed> $input
     */
    private function tokenFrom(Request $request, array $input, string $bodyKey): string
    {
        $fromBody = (string) ($input[$bodyKey] ?? '');
        if ($fromBody !== '') {
            return $fromBody;
        }

        $header = (string) $request->headers->get('Authorization');
        $parts = explode(' ', $header, 2);
        if (count($parts) === 2 && strtolower($parts[0]) === 'bearer') {
            return $parts[1];
        }

        return '';
    }
}
