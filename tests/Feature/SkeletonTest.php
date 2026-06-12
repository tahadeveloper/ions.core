<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\Support\Request;

/*
|--------------------------------------------------------------------------
| Host-app skeleton boot test
|--------------------------------------------------------------------------
| Boots the kernel against the in-repo skeleton (skeleton/) — the reference
| host application that will be split into ionzile/app at release — and
| exercises its routes end-to-end through Kernel::handle().
|
| The skeleton's App\ namespace is intentionally NOT autoloaded by this
| repo's composer.json (it belongs to the future ionzile/app package), so a
| scoped PSR-4 autoloader App\ -> skeleton/app is registered here.
|
| No .env is committed in skeleton/ (and Kernel::boot()'s Dotenv::safeLoad()
| would tolerate a missing one — at the cost of a PHPUnit-surfaced stream
| warning). Instead, each test copies .env.example to a temporary .env and
| removes it afterwards; $_ENV/$_SERVER are snapshotted and restored so the
| skeleton's env never leaks into other tests in the same process.
*/

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = dirname(__DIR__, 2) . '/skeleton/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

function skeletonPath(): string
{
    return dirname(__DIR__, 2) . '/skeleton';
}

/**
 * Remove every test artifact under skeleton/var (compiled Twig templates,
 * cache data, logs) while keeping the committed .gitkeep placeholders.
 */
function cleanSkeletonVar(): void
{
    $var = skeletonPath() . '/var';
    if (!is_dir($var)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($var, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        if ($item->isFile() && $item->getFilename() !== '.gitkeep') {
            @unlink($item->getPathname());
        } elseif ($item->isDir() && !in_array($item->getPathname(), [
            $var . '/cache', $var . '/logs', $var . '/templates',
        ], true)) {
            @rmdir($item->getPathname());
        }
    }
}

beforeEach(function () {
    $this->envSnapshot = $_ENV;
    $this->serverSnapshot = $_SERVER;

    copy(skeletonPath() . '/.env.example', skeletonPath() . '/.env');

    // The skeleton defaults SESSION_DRIVER to 'native'; a native PHP session
    // cannot be exercised from the CLI test process, so opt into the in-memory
    // driver exactly like a host would for its own test runs.
    $_ENV['SESSION_DRIVER'] = 'array';

    // Make boot failures loud: without APP_DEBUG, Kernel::failBoot() die()s,
    // which Pest's output buffering would swallow as a silent exit-0.
    $_ENV['APP_DEBUG'] = 'true';

    bootFixtureKernel(skeletonPath());
});

afterEach(function () {
    @unlink(skeletonPath() . '/.env');
    cleanSkeletonVar();

    $_ENV = $this->envSnapshot;
    $_SERVER = $this->serverSnapshot;
});

test('the skeleton boots: config loads and app.name resolves', function () {
    expect(config('app.name'))->toBeString()->not->toBeEmpty()
        // 4.1 secure defaults are embraced: CORS deny-by-default…
        ->and(config('app.cors.origins'))->toBe([])
        // …and the session cookie_secure flip is NOT overridden to insecure.
        ->and(config('session.cookie_secure'))->toBeNull()
        // The auth surface is pre-allowlisted for AuthMiddleware.
        ->and(config('app.auth.public_paths'))->toContain('/api/auth/login')
        ->and(config('app.auth.public_paths'))->toContain('/api/auth/refresh');
});

test('GET / renders the Twig welcome page', function () {
    $response = Kernel::handle(Request::create('/'));

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getContent())->toContain('Ions PHP framework')
        ->and((string) $response->getContent())->toContain(config('app.name'))
        // 10.6 landing page: quick-start snippet and doc links ship by default
        // (10.8 swapped the raw php -S line for `ions serve`).
        ->and((string) $response->getContent())->toContain('php bin/ions serve')
        ->and((string) $response->getContent())->toContain('ions doctor')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

test('the skeleton ships runnable test scaffolding', function () {
    // phpunit.xml exists, is valid XML and points a testsuite at tests/.
    $phpunitXml = skeletonPath() . '/phpunit.xml';
    expect(is_file($phpunitXml))->toBeTrue();

    $xml = @simplexml_load_string((string) file_get_contents($phpunitXml));
    expect($xml)->not->toBeFalse();

    // The example test exists, lints clean and uses the host-app test kit.
    $exampleTest = skeletonPath() . '/tests/ExampleTest.php';
    expect(is_file($exampleTest))->toBeTrue();

    $lint = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($exampleTest) . ' 2>&1');
    expect($lint)->toContain('No syntax errors detected');

    expect((string) file_get_contents($exampleTest))->toContain('Ions\Testing\TestCase');
});

test('the skeleton ships an Apache front-controller .htaccess', function () {
    $htaccess = skeletonPath() . '/public/.htaccess';
    expect(is_file($htaccess))->toBeTrue();

    $contents = (string) file_get_contents($htaccess);
    expect($contents)->toContain('RewriteEngine')
        // Everything that is not an existing file/directory hits the front controller…
        ->and($contents)->toContain('index.php')
        // …dotfiles (.env, .git, …) are denied outright…
        ->and($contents)->toContain('(^|/)\.')
        // …and the Authorization header survives mod_proxy_fcgi (Bearer API auth).
        ->and($contents)->toContain('E=HTTP_AUTHORIZATION:%{HTTP:Authorization}')
        ->and($contents)->toContain('-MultiViews');
});

test('the skeleton entry points pass the host root explicitly (symlink-safe boot)', function () {
    // public/index.php and bin/ions must call boot(dirname(__DIR__)) rather than
    // bare boot() — the bare form relies on a 5-dirs-up heuristic that resolves
    // wrong when the core is installed via a symlinked path repository.
    $index = (string) file_get_contents(skeletonPath() . '/public/index.php');
    $bin = (string) file_get_contents(skeletonPath() . '/bin/ions');

    expect($index)->toContain('Kernel::boot(dirname(__DIR__))')
        ->and($bin)->toContain('Kernel::boot(dirname(__DIR__))');
});

test('GET /api/ping returns 200 JSON', function () {
    $response = Kernel::handle(Request::create('/api/ping'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toContain('application/json');

    $payload = json_decode((string) $response->getContent(), true);
    expect($payload)->toBe(['status' => 'success', 'data' => ['message' => 'pong']]);
});
