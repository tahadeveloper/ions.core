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
        $jwt = $this->jwt;
        if ($jwt === null) {
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

        // Session fixation hardening: a web-originated login (session exists
        // and is started) gets a fresh session id; data is preserved.
        // Stateless API logins (no started session) are unaffected.
        $this->regenerateSession();

        return Json::ok([
            'access_token'  => $jwt->issue($userId),
            'refresh_token' => $jwt->issueRefresh($userId),
            'token_type'    => 'Bearer',
        ]);
    }

    /**
     * Rotate the framework session id after a successful credential check.
     *
     * Best-effort: a missing session binding or a not-yet-started session is a
     * no-op, and failures never break the login response.
     */
    private function regenerateSession(): void
    {
        try {
            $app = app();
            if (!$app->has('session')) {
                return;
            }
            $session = $app->get('session');
            if ($session instanceof \Ions\Session\SessionManager && $session->isStarted()) {
                $session->regenerate();
            }
        } catch (\Throwable) {
            // Never let session handling break a successful login.
        }
    }

    /**
     * Exchange a refresh token for a fresh access + refresh token pair.
     *
     * Rotation (11.4): the presented refresh token is revoked and BOTH a new
     * access and a new refresh token (same family) are issued. Replaying an
     * already-rotated refresh token revokes the whole family — a sibling token
     * from a compromised lineage is then rejected too.
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
            $tokens = $this->jwt->refresh($token);
        } catch (TokenException) {
            return Json::error('Invalid or expired refresh token', 401);
        }

        return Json::ok([
            'access_token'  => $tokens['access'],
            'refresh_token' => $tokens['refresh'],
            'token_type'    => 'Bearer',
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
     *
     * A tight per-(email+IP) throttle runs on top of any route-level throttle
     * (config app.auth.forgot_throttle = ['max' => 3, 'decay' => 600]).
     * Throttled requests get a 429 with a generic message — consistent with
     * the route 'throttle' middleware and still enumeration-safe (the limit
     * applies whether or not the account exists).
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $provider = $this->resetProvider();
        if ($provider === null) {
            return Json::error('Password reset not supported by the configured provider', 501);
        }

        /** @var array<string,mixed> $input */
        $input = (array) RequestInput::parse($request);

        $throttled = $this->forgotThrottleResponse($request, (string) ($input['email'] ?? ''));
        if ($throttled !== null) {
            return $throttled;
        }

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
     * Per-(email+IP) throttle for the forgot-password endpoint, backed by the
     * shared cache. Returns the 429 response when throttled, null otherwise.
     *
     * Best-effort: when the cache binding is unavailable the throttle is
     * skipped rather than blocking password resets.
     */
    private function forgotThrottleResponse(Request $request, string $email): ?JsonResponse
    {
        try {
            $app = app();
            if (!$app->has('cache')) {
                return null;
            }

            /** @var array<string,mixed> $config */
            $config = (array) config('app.auth.forgot_throttle', []);
            $max = (int) ($config['max'] ?? 3);
            $decay = (int) ($config['decay'] ?? 600);

            /** @var \Illuminate\Cache\CacheManager $manager */
            $manager = $app->get('cache');
            $cache = $manager->store();

            // trim(): MySQL pad-space collations resolve 'a@x.com ' to the
            // same account as 'a@x.com', so the key must collapse them too.
            $key = 'forgot:' . sha1(strtolower(trim($email)) . '|' . (string) $request->getClientIp());

            // add() establishes the key with the TTL (window starts now); a
            // losing concurrent add() is fine — the key then already exists.
            // increment() is only ever called on an existing key, so the
            // counter always carries a TTL (a bare increment() on a missing
            // key would re-create it with no expiry = a permanent lock-out).
            $cache->add($key, 0, $decay);
            // Companion marker carrying the window's absolute end time (same
            // TTL) so a 429 advertises the REMAINING window, not the static
            // decay. add() leaves an existing marker untouched, so the window
            // keeps sliding from the first hit.
            $cache->add($key . ':reset', time() + $decay, $decay);
            $hits = (int) $cache->increment($key);

            if ($hits > $max) {
                $reset = (int) $cache->get($key . ':reset', 0);
                $retryAfter = $reset > 0 ? max(1, $reset - time()) : $decay;
                $response = Json::error('Too many requests', 429, ['retry_after' => $retryAfter]);
                $response->headers->set('Retry-After', (string) $retryAfter);

                return $response;
            }
        } catch (\Throwable) {
            // Never let throttling internals break the reset flow.
        }

        return null;
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
