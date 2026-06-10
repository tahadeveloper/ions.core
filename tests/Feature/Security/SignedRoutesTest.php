<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\Security\Encrypter;
use Ions\Security\UrlSigner;
use Ions\Support\Request;

beforeEach(fn () => bootFixtureKernel());

test("'encrypter' and 'url.signer' are lazy singletons resolving to the Security services", function () {
    expect(Kernel::app()->resolved('encrypter'))->toBeFalse()
        ->and(Kernel::app()->resolved('url.signer'))->toBeFalse();

    $encrypter = Kernel::app()->get('encrypter');
    $signer = Kernel::app()->get('url.signer');

    expect($encrypter)->toBeInstanceOf(Encrypter::class)
        ->and(Kernel::app()->get('encrypter'))->toBe($encrypter)
        ->and($signer)->toBeInstanceOf(UrlSigner::class)
        ->and(Kernel::app()->get('url.signer'))->toBe($signer);
});

test('a normal request never resolves the security bindings (zero hot-path cost)', function () {
    $response = Kernel::handle(Request::create('/ping'));

    expect($response->getStatusCode())->toBe(200)
        ->and(Kernel::app()->resolved('encrypter'))->toBeFalse()
        ->and(Kernel::app()->resolved('url.signer'))->toBeFalse();
});

test('signedRoute generates an absolute, verifiable URL for a named route', function () {
    $url = signedRoute('signed.welcome');

    /** @var UrlSigner $signer */
    $signer = Kernel::app()->get('url.signer');

    expect($url)->toStartWith('http://localhost/signed/welcome?')
        ->and($url)->toContain('signature=')
        ->and($signer->verify($url))->toBeTrue();
});

test('signedRoute substitutes route placeholders and appends extra params to the query', function () {
    $url = signedRoute('signed.download', ['id' => 42, 'disk' => 'local']);

    expect($url)->toStartWith('http://localhost/signed/download/42?')
        ->and($url)->toContain('disk=local');

    /** @var UrlSigner $signer */
    $signer = Kernel::app()->get('url.signer');
    expect($signer->verify($url))->toBeTrue();
});

test('signedUrl signs an arbitrary URL via the container signer', function () {
    $url = signedUrl('/anything?x=1', time() + 60);

    /** @var UrlSigner $signer */
    $signer = Kernel::app()->get('url.signer');
    expect($url)->toContain('expires=')
        ->and($signer->verify($url))->toBeTrue();
});

test("a signed request passes the 'signed' middleware", function () {
    $response = Kernel::handle(Request::create(signedRoute('signed.welcome')));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('signed ok');
});

test('a tampered signed URL is rejected with 403', function () {
    $url = signedRoute('signed.download', ['id' => 42]);
    $tampered = str_replace('/signed/download/42', '/signed/download/43', $url);

    $response = Kernel::handle(Request::create($tampered));

    expect($response->getStatusCode())->toBe(403);
});

test('an expired signed URL is rejected with 403', function () {
    $url = signedRoute('signed.welcome', [], time() - 10);

    $response = Kernel::handle(Request::create($url));

    expect($response->getStatusCode())->toBe(403);
});

test('an unsigned request to a signed route is rejected with 403', function () {
    $response = Kernel::handle(Request::create('/signed/welcome'));

    expect($response->getStatusCode())->toBe(403);
});

test('query param reordering does not break verification end-to-end', function () {
    $url = signedRoute('signed.welcome', ['b' => '2', 'a' => '1'], time() + 600);

    [$base, $query] = explode('?', $url, 2);
    parse_str($query, $params);
    krsort($params);
    $reordered = $base . '?' . http_build_query($params);

    $response = Kernel::handle(Request::create($reordered));

    expect($response->getStatusCode())->toBe(200);
});

test('resolving the security services without a valid APP_KEY throws a RuntimeException naming APP_KEY', function () {
    $snapshot = [
        'env' => $_ENV['APP_KEY'] ?? null,
        'server' => $_SERVER['APP_KEY'] ?? null,
        'getenv' => getenv('APP_KEY'),
    ];

    putenv('APP_KEY=short');
    $_ENV['APP_KEY'] = 'short';
    $_SERVER['APP_KEY'] = 'short';

    try {
        expect(fn () => Kernel::app()->get('url.signer'))
            ->toThrow(RuntimeException::class, 'APP_KEY')
            ->and(fn () => Kernel::app()->get('encrypter'))
            ->toThrow(RuntimeException::class, 'APP_KEY');
    } finally {
        $snapshot['getenv'] === false ? putenv('APP_KEY') : putenv('APP_KEY=' . $snapshot['getenv']);
        if ($snapshot['env'] === null) {
            unset($_ENV['APP_KEY']);
        } else {
            $_ENV['APP_KEY'] = $snapshot['env'];
        }
        if ($snapshot['server'] === null) {
            unset($_SERVER['APP_KEY']);
        } else {
            $_SERVER['APP_KEY'] = $snapshot['server'];
        }
    }
});
