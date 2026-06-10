<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;
use Ions\Support\Request;

/*
|--------------------------------------------------------------------------
| View returns end-to-end through Kernel::handle() (Phase 9.2)
|--------------------------------------------------------------------------
| Fixture pieces:
| - config app.twig.paths: ['admin' => 'views/admin'] (host-root relative)
| - committed templates under tests/fixtures/app/views/
| - fixture controllers under tests/fixtures/app/http-controllers/
|   (PSR-4: IonsFixture\Http\Controllers\ — deliberately OUTSIDE src/Http,
|   which MakeGeneratorsTest wipes between tests)
| - routes in tests/fixtures/app/routes/web.php (/views/*)
|
| The fixture's default twig source is sys_get_temp_dir() (MailableTest
| depends on that), so controller-relative tests point app.twig.source at
| the committed views/ dir per-test before the first render builds the env.
*/

beforeEach(function () {
    bootFixtureKernel();
    config(['app.twig.source' => \Ions\Bundles\Path::root('views')]);
});

test('a closure route returning view(@admin.users.index) renders 200 HTML', function () {
    $response = Kernel::handle(Request::create('/views/helper'));

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getContent())->toBe('admin ns: helper')
        ->and((string) $response->headers->get('Content-Type'))->toContain('text/html');
});

test('a controller action returning view(pages.index) renders through the dispatcher', function () {
    $response = Kernel::handle(Request::create('/views/dotted'));

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getContent())->toBe('pages: dots')
        ->and((string) $response->headers->get('Content-Type'))->toContain('text/html');
});

test('$this->view() from a ROOT controller resolves views/{short-name}/', function () {
    // IonsFixture\Http\Controllers\PagesController -> views/pages/index.twig
    $response = Kernel::handle(Request::create('/views/controller-root'));

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getContent())->toBe('pages: root');
});

test('$this->view() from a NESTED controller resolves the kebab-cased folder path', function () {
    // IonsFixture\Http\Controllers\Admin\UserReports\HomeController
    // -> views/admin/user-reports/index.twig
    $response = Kernel::handle(Request::create('/views/controller-nested'));

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getContent())->toBe('nested: user-reports');
});

test('$viewPath overrides derivation for $this->view()', function () {
    $response = Kernel::handle(Request::create('/views/controller-custom'));

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getContent())->toBe('custom: place');
});

test('view renders reuse the ONE shared Twig environment across requests', function () {
    Kernel::handle(Request::create('/views/helper'));
    $first = Kernel::app()->get('view.env');
    Kernel::handle(Request::create('/views/controller-root'));
    $second = Kernel::app()->get('view.env');

    expect(spl_object_id($second))->toBe(spl_object_id($first));
});
