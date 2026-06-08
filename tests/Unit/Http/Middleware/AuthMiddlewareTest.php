<?php

use Ions\Http\Middleware\AuthMiddleware;
use Ions\Security\Jwt;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

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
