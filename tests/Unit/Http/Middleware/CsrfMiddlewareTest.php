<?php

use Ions\Http\Middleware\CsrfMiddleware;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;

// simple in-memory token storage for tests
function inMemoryCsrfManager(): CsrfTokenManager
{
    $storage = new class () implements TokenStorageInterface {
        private array $tokens = [];
        public function getToken(string $tokenId): string
        {
            if (!isset($this->tokens[$tokenId])) {
                throw new \Symfony\Component\Security\Csrf\Exception\TokenNotFoundException();
            }
            return $this->tokens[$tokenId];
        }
        public function setToken(string $tokenId, #[\SensitiveParameter] string $token): void
        {
            $this->tokens[$tokenId] = $token;
        }
        public function removeToken(string $tokenId): ?string
        {
            $t = $this->tokens[$tokenId] ?? null;
            unset($this->tokens[$tokenId]);
            return $t;
        }
        public function hasToken(string $tokenId): bool
        {
            return isset($this->tokens[$tokenId]);
        }
    };
    return new CsrfTokenManager(new UriSafeTokenGenerator(), $storage);
}

test('safe methods (GET) pass through without a token', function () {
    $mw = new CsrfMiddleware(inMemoryCsrfManager());
    $res = $mw->handle(Request::create('/form', 'GET'), fn ($r) => new Response('ok'));
    expect($res->getContent())->toBe('ok');
});

test('POST without a valid token → 419 HttpException (themeable via errors/419.twig)', function () {
    $mw = new CsrfMiddleware(inMemoryCsrfManager());
    $reached = false;

    try {
        $mw->handle(Request::create('/form', 'POST'), function ($r) use (&$reached) {
            $reached = true;
            return new Response('ok');
        });
        $this->fail('Expected a 419 HttpException.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        expect($e->getStatusCode())->toBe(419)
            ->and($e->getMessage())->toBe('CSRF token mismatch.')
            ->and($reached)->toBeFalse();
    }
});

test('POST with a valid token passes', function () {
    $manager = inMemoryCsrfManager();
    $token = $manager->getToken('web')->getValue(); // generates + stores
    $mw = new CsrfMiddleware($manager);
    $req = Request::create('/form', 'POST', ['_ion_token' => $token]);
    $res = $mw->handle($req, fn ($r) => new Response('ok'));
    expect($res->getContent())->toBe('ok');
});

test('POST with a token in the X-CSRF-TOKEN header passes', function () {
    $manager = inMemoryCsrfManager();
    $token = $manager->getToken('web')->getValue();
    $mw = new CsrfMiddleware($manager);
    $req = Request::create('/form', 'POST');
    $req->headers->set('X-CSRF-TOKEN', $token);
    $res = $mw->handle($req, fn ($r) => new Response('ok'));
    expect($res->getContent())->toBe('ok');
});
