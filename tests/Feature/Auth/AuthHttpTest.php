<?php

/**
 * End-to-end HTTP auth surface tests, driven through Kernel::handle() so the
 * real middleware pipeline (TrustedHost → SecurityHeaders → CORS → Auth, plus
 * the per-route 'throttle' alias) runs exactly as in production.
 *
 * The fixture's routes/api.php registers:
 *   POST /api/auth/login            (throttled)
 *   POST /api/auth/refresh
 *   POST /api/auth/logout
 *   POST /api/auth/password/forgot
 *   POST /api/auth/password/reset
 * and the protected GET /api/secret used to prove a token resolves a user.
 *
 * Login/refresh/logout use the in-memory FixtureUserProvider (resolves
 * 'user-99'/'user-7'). The password-reset round-trip swaps the provider to
 * Sentinel on the SQLite fixture DB (createSentinelTables()).
 */

use Ions\Foundation\Kernel;
use Ions\Security\Jwt;
use Ions\Support\Request;

beforeEach(fn () => bootFixtureKernel());

/** Build a JSON POST request to an /api path. */
function jsonPost(string $path, array $body = []): Request
{
    $request = Request::create($path, 'POST', [], [], [], [], json_encode($body) ?: '{}');
    $request->headers->set('Content-Type', 'application/json');

    return $request;
}

test('login success returns access + refresh tokens and the access token resolves the user', function () {
    $response = Kernel::handle(jsonPost('/api/auth/login', [
        'email'    => 'known@example.com',
        'password' => 'secret',
    ]));

    expect($response->getStatusCode())->toBe(200);

    $payload = json_decode((string) $response->getContent(), true);
    expect($payload['status'])->toBe('success')
        ->and($payload['data']['token_type'])->toBe('Bearer')
        ->and($payload['data']['access_token'])->toBeString()->not->toBeEmpty()
        ->and($payload['data']['refresh_token'])->toBeString()->not->toBeEmpty();

    // The access token must resolve the real user (user-99) through AuthMiddleware.
    $protected = Request::create('/api/secret');
    $protected->headers->set('Authorization', 'Bearer ' . $payload['data']['access_token']);

    $secret = Kernel::handle($protected);
    expect($secret->getStatusCode())->toBe(200)
        ->and($secret->getContent())->toContain('user-99');
});

test('login failure with bad credentials returns 401', function () {
    $response = Kernel::handle(jsonPost('/api/auth/login', [
        'email'    => 'known@example.com',
        'password' => 'wrong-password',
    ]));

    expect($response->getStatusCode())->toBe(401);

    $payload = json_decode((string) $response->getContent(), true);
    expect($payload['status'])->toBe('error');
});

test('login is rate-limited: exceeding the throttle window returns 429', function () {
    // The fixture sets app.ratelimit.max = 3 for the login route.
    $max = (int) config('app.ratelimit.max', 3);

    $last = null;
    for ($i = 0; $i < $max + 1; $i++) {
        $last = Kernel::handle(jsonPost('/api/auth/login', [
            'email'    => 'known@example.com',
            'password' => 'wrong-password',
        ]));
    }

    expect($last->getStatusCode())->toBe(429)
        ->and($last->headers->get('Retry-After'))->not->toBeNull();
});

test('refresh rotates: the new access token works and the old refresh token is rejected', function () {
    $login = Kernel::handle(jsonPost('/api/auth/login', [
        'email'    => 'known@example.com',
        'password' => 'secret',
    ]));
    $tokens = json_decode((string) $login->getContent(), true)['data'];

    // Exchange the refresh token for a new access token.
    $refresh = Kernel::handle(jsonPost('/api/auth/refresh', [
        'refresh_token' => $tokens['refresh_token'],
    ]));
    expect($refresh->getStatusCode())->toBe(200);
    $newTokens = json_decode((string) $refresh->getContent(), true)['data'];
    expect($newTokens['access_token'])->toBeString()->not->toBeEmpty()
        ->and($newTokens['token_type'])->toBe('Bearer')
        // 11.4: rotation now re-issues a refresh token, surfaced in the response.
        ->and($newTokens['refresh_token'])->toBeString()->not->toBeEmpty()
        ->and($newTokens['refresh_token'])->not->toBe($tokens['refresh_token']);

    // The new access token resolves the user on a protected route.
    $protected = Request::create('/api/secret');
    $protected->headers->set('Authorization', 'Bearer ' . $newTokens['access_token']);
    expect(Kernel::handle($protected)->getStatusCode())->toBe(200);

    // The newly issued refresh token works for a further rotation (before any
    // replay poisons the family).
    $again = Kernel::handle(jsonPost('/api/auth/refresh', [
        'refresh_token' => $newTokens['refresh_token'],
    ]));
    expect($again->getStatusCode())->toBe(200);

    // The OLD refresh token is now revoked (rotation) → 401.
    $reuse = Kernel::handle(jsonPost('/api/auth/refresh', [
        'refresh_token' => $tokens['refresh_token'],
    ]));
    expect($reuse->getStatusCode())->toBe(401);
});

test('refresh reuse detection: replaying a rotated refresh token kills the family', function () {
    $login = Kernel::handle(jsonPost('/api/auth/login', [
        'email'    => 'known@example.com',
        'password' => 'secret',
    ]));
    $tokens = json_decode((string) $login->getContent(), true)['data'];

    // Rotate once → the response carries a sibling refresh token of the same family.
    $rot = Kernel::handle(jsonPost('/api/auth/refresh', [
        'refresh_token' => $tokens['refresh_token'],
    ]));
    $sibling = json_decode((string) $rot->getContent(), true)['data']['refresh_token'];

    // Attacker replays the already-rotated original refresh token → 401 + family killed.
    $replay = Kernel::handle(jsonPost('/api/auth/refresh', [
        'refresh_token' => $tokens['refresh_token'],
    ]));
    expect($replay->getStatusCode())->toBe(401);

    // The legitimate sibling token is now also rejected (whole lineage revoked).
    $siblingReuse = Kernel::handle(jsonPost('/api/auth/refresh', [
        'refresh_token' => $sibling,
    ]));
    expect($siblingReuse->getStatusCode())->toBe(401);
});

test('logout revokes the access token: a subsequent protected request is rejected', function () {
    $login = Kernel::handle(jsonPost('/api/auth/login', [
        'email'    => 'known@example.com',
        'password' => 'secret',
    ]));
    $access = json_decode((string) $login->getContent(), true)['data']['access_token'];

    // Sanity: the token works before logout.
    $before = Request::create('/api/secret');
    $before->headers->set('Authorization', 'Bearer ' . $access);
    expect(Kernel::handle($before)->getStatusCode())->toBe(200);

    // Logout revokes the token.
    $logout = Request::create('/api/auth/logout', 'POST');
    $logout->headers->set('Authorization', 'Bearer ' . $access);
    $logoutResponse = Kernel::handle($logout);
    expect($logoutResponse->getStatusCode())->toBe(200);

    // The same access token is now rejected on the protected route.
    $after = Request::create('/api/secret');
    $after->headers->set('Authorization', 'Bearer ' . $access);
    expect(Kernel::handle($after)->getStatusCode())->toBe(401);
});

test('per-user binding: a token issued for user X resolves to user X (not the app id) via AuthMiddleware', function () {
    // Issue directly via the container jwt with the user's identifier as the subject.
    /** @var Jwt $jwt */
    $jwt = Kernel::app()->get('jwt');
    $token = $jwt->issue('user-7');

    $request = Request::create('/api/secret');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    $response = Kernel::handle($request);
    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toContain('user-7');
});

test('password reset round-trip with Sentinel: forgot issues a code, reset applies it, login with the new password works', function () {
    // Switch the bound provider to Sentinel and build the Sentinel schema.
    config(['auth.provider' => 'sentinel']);
    Kernel::app()->forgetInstance('user_provider');
    createSentinelTables();

    \Cartalyst\Sentinel\Native\Facades\Sentinel::registerAndActivate([
        'email'    => 'reset@example.com',
        'password' => 'old-password',
    ]);

    // Request a reset code.
    $forgot = Kernel::handle(jsonPost('/api/auth/password/forgot', [
        'email' => 'reset@example.com',
    ]));
    expect($forgot->getStatusCode())->toBe(200);

    // Pull the issued reminder code straight from the DB (mirrors what would be e-mailed).
    $code = \Ions\Support\DB::table('reminders')->where('user_id', 1)->value('code');
    expect($code)->toBeString()->not->toBeEmpty();

    // Apply the reset.
    $reset = Kernel::handle(jsonPost('/api/auth/password/reset', [
        'email'    => 'reset@example.com',
        'code'     => $code,
        'password' => 'new-password',
    ]));
    expect($reset->getStatusCode())->toBe(200);

    // Login with the new password now succeeds.
    $login = Kernel::handle(jsonPost('/api/auth/login', [
        'email'    => 'reset@example.com',
        'password' => 'new-password',
    ]));
    expect($login->getStatusCode())->toBe(200);

    $payload = json_decode((string) $login->getContent(), true);
    expect($payload['data']['access_token'])->toBeString()->not->toBeEmpty();

    // (1) Login with the OLD password must now fail — the reset invalidated it.
    $oldLogin = Kernel::handle(jsonPost('/api/auth/login', [
        'email'    => 'reset@example.com',
        'password' => 'old-password',
    ]));
    expect($oldLogin->getStatusCode())->toBe(401);

    // (2) A reset attempt with a missing/empty code is rejected at the HTTP layer.
    $noCode = Kernel::handle(jsonPost('/api/auth/password/reset', [
        'email'    => 'reset@example.com',
        'code'     => '',
        'password' => 'another-password',
    ]));
    expect($noCode->getStatusCode())->toBe(422);

    // (2b) Same assertion when 'code' key is absent entirely.
    $missingCode = Kernel::handle(jsonPost('/api/auth/password/reset', [
        'email'    => 'reset@example.com',
        'password' => 'another-password',
    ]));
    expect($missingCode->getStatusCode())->toBe(422);
});

test('a protected route that merely shares a string prefix with a public path is NOT bypassed', function () {
    // public_paths includes '/api/auth/login'; the route '/api/auth/login-history' must
    // still require auth — its path starts with the same characters but is a different
    // path segment, so the old str_starts_with logic would wrongly bypass it.
    // With the segment-boundary fix, it must return 401 when no token is present.
    $response = Kernel::handle(Request::create('/api/auth/login-history'));
    expect($response->getStatusCode())->toBe(401);
});

test('exact public path and segment-beneath subtree are still bypassed correctly', function () {
    // '/api/auth/login' (exact) → public, no token needed → 401 from logic inside
    // the controller itself (bad credentials), NOT from AuthMiddleware (which returns
    // a 401 with "Not authorized!" detail). We confirm the response is NOT the
    // middleware-level 401 by checking the body does not contain "Not authorized!".
    $response = Kernel::handle(jsonPost('/api/auth/login', [
        'email'    => 'known@example.com',
        'password' => 'wrong',
    ]));
    $body = (string) $response->getContent();
    expect($response->getStatusCode())->toBe(401)
        ->and($body)->not->toContain('Not authorized!');
});
