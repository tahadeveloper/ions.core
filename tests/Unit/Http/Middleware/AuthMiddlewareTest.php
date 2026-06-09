<?php

use Ions\Auth\Contracts\Authenticatable;
use Ions\Auth\Contracts\UserProvider;
use Ions\Http\Middleware\AuthMiddleware;
use Ions\Security\Jwt;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

// ---------------------------------------------------------------------------
// Test helpers: inline fake implementations of the auth contracts
// ---------------------------------------------------------------------------

function fakeUser(string $id): Authenticatable
{
    return new class ($id) implements Authenticatable {
        public function __construct(private string $id)
        {
        }

        public function getAuthIdentifier(): string|int
        {
            return $this->id;
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }
    };
}

function providerWith(?Authenticatable $user): UserProvider
{
    return new class ($user) implements UserProvider {
        public function __construct(private ?Authenticatable $user)
        {
        }

        public function retrieveById(string|int $id): ?Authenticatable
        {
            return $this->user;
        }

        /** @param array<string,mixed> $c */
        public function retrieveByCredentials(array $c): ?Authenticatable
        {
            return $this->user;
        }

        /** @param array<string,mixed> $c */
        public function validateCredentials(Authenticatable $u, array $c): bool
        {
            return false;
        }
    };
}

beforeEach(function () {
    $this->secret = str_repeat('k', 32);
    $this->jwt = new Jwt($this->secret, 'ions', 'ions', 3600);
    $this->mw = new AuthMiddleware($this->jwt);
    $this->terminal = fn (Request $r) => new Response('ok', 200);
});

test('missing Authorization header → 401', function () {
    expect($this->mw->handle(Request::create('/api'), $this->terminal)->getStatusCode())->toBe(401);
});

test('non-Bearer scheme → 401', function () {
    $req = Request::create('/api');
    $req->headers->set('Authorization', 'Basic abc');
    expect($this->mw->handle($req, $this->terminal)->getStatusCode())->toBe(401);
});

test('malformed/garbage token → 401', function () {
    $req = Request::create('/api');
    $req->headers->set('Authorization', 'Bearer not-a-jwt');
    expect($this->mw->handle($req, $this->terminal)->getStatusCode())->toBe(401);
});

test('expired token → 401', function () {
    $expiredToken = (new Jwt($this->secret, 'ions', 'ions', -10))->issue('7');
    $req = Request::create('/api');
    $req->headers->set('Authorization', 'Bearer ' . $expiredToken);
    expect($this->mw->handle($req, $this->terminal)->getStatusCode())->toBe(401);
});

test('wrong-signature token → 401', function () {
    $other = (new Jwt(str_repeat('z', 32), 'ions', 'ions', 3600))->issue('7');
    $req = Request::create('/api');
    $req->headers->set('Authorization', 'Bearer ' . $other);
    expect($this->mw->handle($req, $this->terminal)->getStatusCode())->toBe(401);
});

test('valid token → passes through (200) and attaches auth_user_id', function () {
    $token = $this->jwt->issue('42');
    $req = Request::create('/api');
    $req->headers->set('Authorization', 'Bearer ' . $token);
    $res = $this->mw->handle($req, $this->terminal);
    expect($res->getStatusCode())->toBe(200)
        ->and($req->attributes->get('auth_user_id'))->toBe('42');
});

test('case-insensitive bearer scheme is accepted', function () {
    $token = $this->jwt->issue('9');
    $req = Request::create('/api');
    $req->headers->set('Authorization', 'bearer ' . $token);
    expect($this->mw->handle($req, $this->terminal)->getStatusCode())->toBe(200);
});

test('null jwt (no signing key configured) → 401, never fatals', function () {
    $mw = new AuthMiddleware(null);
    $req = Request::create('/api');
    $req->headers->set('Authorization', 'Bearer whatever');
    expect($mw->handle($req, $this->terminal)->getStatusCode())->toBe(401);
});

// ---------------------------------------------------------------------------
// Task 5.3: UserProvider integration tests (D5-B)
// ---------------------------------------------------------------------------

test('valid token + known user → 200 and attaches the resolved user', function () {
    $token = $this->jwt->issue('42');
    $mw = new AuthMiddleware($this->jwt, providerWith(fakeUser('42')));
    $req = Request::create('/api');
    $req->headers->set('Authorization', 'Bearer ' . $token);
    $res = $mw->handle($req, $this->terminal);
    expect($res->getStatusCode())->toBe(200)
        ->and($req->attributes->get('auth_user_id'))->toBe('42')
        ->and($req->attributes->get('auth_user'))->toBeInstanceOf(Authenticatable::class)
        ->and($req->attributes->get('auth_user')->getAuthIdentifier())->toBe('42');
});

test('valid token but the user no longer exists → 401 (D5-B)', function () {
    $token = $this->jwt->issue('42');
    $mw = new AuthMiddleware($this->jwt, providerWith(null)); // provider returns null = user gone
    $req = Request::create('/api');
    $req->headers->set('Authorization', 'Bearer ' . $token);
    expect($mw->handle($req, $this->terminal)->getStatusCode())->toBe(401);
});

test('no provider configured → id-only attach (BC, still 200)', function () {
    $token = $this->jwt->issue('42');
    $mw = new AuthMiddleware($this->jwt, null); // no provider
    $req = Request::create('/api');
    $req->headers->set('Authorization', 'Bearer ' . $token);
    $res = $mw->handle($req, $this->terminal);
    expect($res->getStatusCode())->toBe(200)
        ->and($req->attributes->get('auth_user_id'))->toBe('42')
        ->and($req->attributes->get('auth_user'))->toBeNull();
});
