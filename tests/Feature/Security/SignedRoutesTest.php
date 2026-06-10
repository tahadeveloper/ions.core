<?php

declare(strict_types=1);

use Ions\Bundles\Route;
use Ions\Foundation\Kernel;
use Ions\Security\Encrypter;
use Ions\Security\UrlSigner;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

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

// ---------------------------------------------------------------------------
// Fail-closed regressions: a signed route must NEVER serve an unsigned request,
// even when the middleware itself cannot come up (broken key / missing alias).
// ---------------------------------------------------------------------------

test('a short APP_KEY fails closed in production: unsigned request to a signed route returns 500, never 200', function () {
    $snapshot = [
        'key_env' => $_ENV['APP_KEY'] ?? null,
        'key_server' => $_SERVER['APP_KEY'] ?? null,
        'key_getenv' => getenv('APP_KEY'),
        'debug_getenv' => getenv('APP_DEBUG'),
        'debug_env' => $_ENV['APP_DEBUG'] ?? null,
    ];

    putenv('APP_KEY=short');
    $_ENV['APP_KEY'] = 'short';
    $_SERVER['APP_KEY'] = 'short';
    putenv('APP_DEBUG=false');
    $_ENV['APP_DEBUG'] = 'false';

    try {
        $response = Kernel::handle(Request::create('/signed/welcome'));

        expect($response->getStatusCode())->not->toBe(200)
            ->and($response->getStatusCode())->toBe(500);
    } finally {
        $snapshot['key_getenv'] === false ? putenv('APP_KEY') : putenv('APP_KEY=' . $snapshot['key_getenv']);
        if ($snapshot['key_env'] === null) {
            unset($_ENV['APP_KEY']);
        } else {
            $_ENV['APP_KEY'] = $snapshot['key_env'];
        }
        if ($snapshot['key_server'] === null) {
            unset($_SERVER['APP_KEY']);
        } else {
            $_SERVER['APP_KEY'] = $snapshot['key_server'];
        }
        $snapshot['debug_getenv'] === false ? putenv('APP_DEBUG') : putenv('APP_DEBUG=' . $snapshot['debug_getenv']);
        if ($snapshot['debug_env'] === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $snapshot['debug_env'];
        }
    }
});

test("a missing 'signed' alias fails closed in production: the route 500s instead of running unprotected", function () {
    $aliasesSnapshot = config('app.middleware_aliases', []);
    $debugSnapshot = ['getenv' => getenv('APP_DEBUG'), 'env' => $_ENV['APP_DEBUG'] ?? null];

    // Host config override that forgot (or removed) the 'signed' alias while
    // routes still declare ->middleware(['signed']).
    $aliases = (array) $aliasesSnapshot;
    unset($aliases['signed']);
    config(['app.middleware_aliases' => $aliases]);

    putenv('APP_DEBUG=false');
    $_ENV['APP_DEBUG'] = 'false';

    try {
        $response = Kernel::handle(Request::create('/signed/welcome'));

        expect($response->getStatusCode())->not->toBe(200)
            ->and($response->getStatusCode())->toBe(500);
    } finally {
        config(['app.middleware_aliases' => $aliasesSnapshot]);
        $debugSnapshot['getenv'] === false ? putenv('APP_DEBUG') : putenv('APP_DEBUG=' . $debugSnapshot['getenv']);
        if ($debugSnapshot['env'] === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $debugSnapshot['env'];
        }
    }
});

// ---------------------------------------------------------------------------
// APP_FOLDER / subfolder deployments: app.app_url already contains the folder,
// so signedRoute() must not let the request baseUrl double it.
// ---------------------------------------------------------------------------

test('signedRoute emits the app folder exactly once when deployed under a subfolder', function () {
    config(['app.app_url' => 'http://localhost/myapp']);

    Route::get('/gen-link', fn () => new Response(signedRoute('signed.welcome')));

    // SCRIPT_NAME/SCRIPT_FILENAME make Symfony derive baseUrl '/myapp'
    // (front controller at /myapp/index.php), like a real subfolder deploy.
    $request = Request::create('http://localhost/myapp/gen-link', 'GET', [], [], [], [
        'SCRIPT_NAME' => '/myapp/index.php',
        'SCRIPT_FILENAME' => '/myapp/index.php',
        'PHP_SELF' => '/myapp/index.php',
    ]);
    expect($request->getBaseUrl())->toBe('/myapp');

    $response = Kernel::handle($request);
    $url = (string) $response->getContent();

    expect($response->getStatusCode())->toBe(200)
        ->and($url)->toStartWith('http://localhost/myapp/signed/welcome?')
        ->and(substr_count($url, '/myapp/'))->toBe(1);

    /** @var UrlSigner $signer */
    $signer = Kernel::app()->get('url.signer');
    expect($signer->verify($url))->toBeTrue();
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
