<?php

use Ions\Bundles\Path;
use Ions\View\AssetExtension;

/*
|--------------------------------------------------------------------------
| AssetExtension (Phase 9.5) — vite() + asset() Twig functions
|--------------------------------------------------------------------------
| Each test runs against a throwaway host root (Path::setBasePath) so the
| fixture app's public/ is never touched and no node tooling is required:
| manifest/hot fixtures are plain files written by the test itself.
*/

function makeAssetHost(): string
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ions-assets-' . bin2hex(random_bytes(4));
    mkdir($base . '/public/build', 0777, true);

    return $base;
}

beforeEach(function () {
    bootFixtureKernel();
    $this->host = makeAssetHost();
    Path::setBasePath($this->host);
    $this->ext = new AssetExtension();
});

afterEach(function () {
    Path::resetBasePath();
    \Ions\Support\File::deleteDirectory($this->host);
});

// ---------------------------------------------------------------------------
// vite() — manifest (build) mode
// ---------------------------------------------------------------------------

test('vite() emits css links then the module script from public/build/manifest.json', function () {
    file_put_contents($this->host . '/public/build/manifest.json', json_encode([
        'resources/js/app.js' => [
            'file' => 'assets/app-4ed993c7.js',
            'css'  => ['assets/app-deadbeef.css'],
        ],
    ]));

    $html = $this->ext->vite('resources/js/app.js');

    expect($html)->toContain('<link rel="stylesheet" href="http://localhost/build/assets/app-deadbeef.css">')
        ->and($html)->toContain('<script type="module" src="http://localhost/build/assets/app-4ed993c7.js"></script>')
        // CSS first so styles are ready before the module executes.
        ->and(strpos($html, 'app-deadbeef.css'))->toBeLessThan((int) strpos($html, 'app-4ed993c7.js'));
});

test('vite() falls back to the Vite >=5 default location public/build/.vite/manifest.json', function () {
    // A hand-rolled host config with `manifest: true` lands here on real builds.
    mkdir($this->host . '/public/build/.vite', 0777, true);
    file_put_contents($this->host . '/public/build/.vite/manifest.json', json_encode([
        'resources/js/app.js' => [
            'file' => 'assets/app-4ed993c7.js',
            'css'  => ['assets/app-deadbeef.css'],
        ],
    ]));

    $html = $this->ext->vite('resources/js/app.js');

    expect($html)->toContain('<link rel="stylesheet" href="http://localhost/build/assets/app-deadbeef.css">')
        ->and($html)->toContain('<script type="module" src="http://localhost/build/assets/app-4ed993c7.js"></script>');
});

test('vite() prefers public/build/manifest.json over the .vite/ fallback', function () {
    file_put_contents($this->host . '/public/build/manifest.json', json_encode([
        'resources/js/app.js' => ['file' => 'assets/app-primary.js'],
    ]));
    mkdir($this->host . '/public/build/.vite', 0777, true);
    file_put_contents($this->host . '/public/build/.vite/manifest.json', json_encode([
        'resources/js/app.js' => ['file' => 'assets/app-fallback.js'],
    ]));

    expect($this->ext->vite('resources/js/app.js'))->toContain('app-primary.js')
        ->and($this->ext->vite('resources/js/app.js'))->not->toContain('app-fallback.js');
});

test('vite() memoizes manifest reads per instance — one read per request', function () {
    file_put_contents($this->host . '/public/build/manifest.json', json_encode([
        'resources/js/app.js' => ['file' => 'assets/app-first.js'],
    ]));

    $first = $this->ext->vite('resources/js/app.js');

    // A rewrite mid-request is not re-read by the same instance...
    file_put_contents($this->host . '/public/build/manifest.json', json_encode([
        'resources/js/app.js' => ['file' => 'assets/app-second.js'],
    ]));

    expect($this->ext->vite('resources/js/app.js'))->toBe($first)
        // ...but a fresh instance (next request) sees the new build.
        ->and((new AssetExtension())->vite('resources/js/app.js'))->toContain('app-second.js');
});

test('vite() handles a chunk without css entries', function () {
    file_put_contents($this->host . '/public/build/manifest.json', json_encode([
        'resources/js/app.js' => ['file' => 'assets/app-cafef00d.js'],
    ]));

    $html = $this->ext->vite('resources/js/app.js');

    expect($html)->toContain('assets/app-cafef00d.js')
        ->and($html)->not->toContain('<link');
});

// ---------------------------------------------------------------------------
// vite() — hot (dev server) mode
// ---------------------------------------------------------------------------

test('vite() prefers the hot dev server when public/hot exists', function () {
    file_put_contents($this->host . '/public/build/manifest.json', json_encode([
        'resources/js/app.js' => ['file' => 'assets/app-4ed993c7.js'],
    ]));
    file_put_contents($this->host . '/public/hot', "http://localhost:5173\n");

    $html = $this->ext->vite('resources/js/app.js');

    expect($html)->toContain('<script type="module" src="http://localhost:5173/@vite/client"></script>')
        ->and($html)->toContain('<script type="module" src="http://localhost:5173/resources/js/app.js"></script>')
        ->and($html)->not->toContain('build/assets');
});

test('vite() ignores a garbage hot file and falls through to manifest mode', function () {
    // Defense-in-depth: only an http(s) origin may redirect script URLs.
    file_put_contents($this->host . '/public/hot', "javascript:alert(1)//\n");
    file_put_contents($this->host . '/public/build/manifest.json', json_encode([
        'resources/js/app.js' => ['file' => 'assets/app-4ed993c7.js'],
    ]));

    $html = $this->ext->vite('resources/js/app.js');

    expect($html)->toContain('http://localhost/build/assets/app-4ed993c7.js')
        ->and($html)->not->toContain('@vite/client')
        ->and($html)->not->toContain('javascript:');
});

// ---------------------------------------------------------------------------
// vite() — failure modes never throw (a missing build must not 500 the page)
// ---------------------------------------------------------------------------

test('vite() returns an HTML comment and logs a warning when the manifest is missing', function () {
    $html = $this->ext->vite('resources/js/app.js');

    expect($html)->toContain('<!-- vite: manifest not found')
        ->and($html)->toContain('npm run build');

    $log = $this->host . '/var/logs/view.log';
    expect(is_file($log))->toBeTrue()
        ->and((string) file_get_contents($log))->toContain('manifest not found');
});

test('vite() returns an HTML comment when the entry is missing from the manifest', function () {
    file_put_contents($this->host . '/public/build/manifest.json', json_encode([
        'resources/js/other.js' => ['file' => 'assets/other-12345678.js'],
    ]));

    $html = $this->ext->vite('resources/js/app.js');

    expect($html)->toStartWith('<!-- vite:')
        ->and($html)->toContain('resources/js/app.js');
});

test('vite() escapes attribute values in its markup', function () {
    file_put_contents($this->host . '/public/hot', 'http://localhost:5173');

    $html = $this->ext->vite('resources/js/"x".js');

    expect($html)->toContain('&quot;x&quot;')
        ->and($html)->not->toContain('"x"');
});

// ---------------------------------------------------------------------------
// asset()
// ---------------------------------------------------------------------------

test('asset() builds an app_url URL with a filemtime cache-buster', function () {
    mkdir($this->host . '/public/css', 0777, true);
    file_put_contents($this->host . '/public/css/app.css', 'body{}');
    $mtime = filemtime($this->host . '/public/css/app.css');

    expect($this->ext->asset('css/app.css'))->toBe('http://localhost/css/app.css?v=' . $mtime);
});

test('asset() omits the buster when the file is missing and never throws', function () {
    expect($this->ext->asset('js/missing.js'))->toBe('http://localhost/js/missing.js');
});

test('asset() skips the filemtime buster when the path contains ".."', function () {
    // Never probe mtimes outside public/ — the URL is still returned as-is.
    file_put_contents($this->host . '/outside.txt', 'secret');

    expect($this->ext->asset('../outside.txt'))->toBe('http://localhost/../outside.txt');
});

test('asset() normalizes slashes between app_url and the path', function () {
    config(['app.app_url' => 'http://example.test/']);

    expect($this->ext->asset('/css/app.css'))->toBe('http://example.test/css/app.css');
});

// ---------------------------------------------------------------------------
// Twig wiring
// ---------------------------------------------------------------------------

test('the extension exposes vite (html-safe) and asset functions', function () {
    $functions = [];
    foreach ($this->ext->getFunctions() as $function) {
        $functions[$function->getName()] = $function;
    }

    expect($functions)->toHaveKeys(['vite', 'asset'])
        // vite() returns markup — it must be html-safe or autoescape mangles it.
        ->and($functions['vite']->getSafe(new \Twig\Node\EmptyNode()))->toBe(['html'])
        // asset() returns a plain URL string and stays auto-escaped.
        ->and($functions['asset']->getSafe(new \Twig\Node\EmptyNode()))->toBe([]);
});
