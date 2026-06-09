<?php

use Ions\Foundation\Kernel;
use Ions\Support\Request;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;

beforeEach(fn () => bootFixtureKernel());

test('a state-changing web POST without a CSRF token is rejected with 419', function () {
    $response = Kernel::handle(Request::create('/csrf-protected', 'POST'));
    expect($response->getStatusCode())->toBe(419);
});

test('a state-changing web POST with a valid CSRF token passes', function () {
    // Swap the container csrf manager for an in-memory one we control, generate a token, send it.
    $storage = new class () implements TokenStorageInterface {
        /** @var array<string, string> */
        private array $t = [];

        public function getToken(string $id): string
        {
            if (!isset($this->t[$id])) {
                throw new \Symfony\Component\Security\Csrf\Exception\TokenNotFoundException();
            }
            return $this->t[$id];
        }

        public function setToken(string $id, #[\SensitiveParameter] string $token): void
        {
            $this->t[$id] = $token;
        }

        public function removeToken(string $id): ?string
        {
            $v = $this->t[$id] ?? null;
            unset($this->t[$id]);
            return $v;
        }

        public function hasToken(string $id): bool
        {
            return isset($this->t[$id]);
        }
    };

    $manager = new \Symfony\Component\Security\Csrf\CsrfTokenManager(
        new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator(),
        $storage
    );

    // Override the binding before handle() builds the stack via defaultMiddleware().
    Kernel::app()->instance('csrf', $manager);

    $token = $manager->getToken('web')->getValue();

    $request = Request::create('/csrf-protected', 'POST', ['_ion_token' => $token]);
    $response = Kernel::handle($request);
    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('posted');
});

test('a safe GET web route is unaffected by CSRF', function () {
    expect(Kernel::handle(Request::create('/ping'))->getStatusCode())->toBe(200);
});

test('CSRF can be disabled via config', function () {
    config(['app.csrf.enabled' => false]);
    // With CSRF off, a POST passes without a token.
    expect(Kernel::handle(Request::create('/csrf-protected', 'POST'))->getStatusCode())->toBe(200);
});
