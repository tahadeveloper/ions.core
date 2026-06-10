<?php

declare(strict_types=1);

use Ions\View\View;

/*
|--------------------------------------------------------------------------
| view() helper — pure name translation (Phase 9.2)
|--------------------------------------------------------------------------
| The helper performs NO filesystem checks: it only translates the template
| name (dots -> '/', '@ns.' -> '@ns/', append '.twig' when absent) and wraps
| it with the data in an Ions\View\View renderable. Twig itself errors at
| render time when the template is missing.
*/

beforeEach(fn () => bootFixtureKernel());

test('view() returns an Ions\View\View renderable carrying the data', function () {
    $view = view('home', ['name' => 'ions']);

    expect($view)->toBeInstanceOf(View::class)
        ->and($view->template)->toBe('home.twig')
        ->and($view->data)->toBe(['name' => 'ions']);
});

test('view() translates template names without touching the filesystem', function (string $input, string $expected) {
    expect(view($input)->template)->toBe($expected);
})->with([
    'dots become directory separators' => ['users.index', 'users/index.twig'],
    'slashes pass through' => ['users/index', 'users/index.twig'],
    'existing .twig extension is kept' => ['users/index.twig', 'users/index.twig'],
    'dots before a kept extension still translate' => ['users.index.twig', 'users/index.twig'],
    'bare name gets the extension' => ['home', 'home.twig'],
    'namespace with dots' => ['@admin.users.index', '@admin/users/index.twig'],
    'namespace with slashes' => ['@admin/users/index', '@admin/users/index.twig'],
    'namespace with extension' => ['@admin/users/index.twig', '@admin/users/index.twig'],
    'namespace mixed separators' => ['@mail.orders/shipped', '@mail/orders/shipped.twig'],
]);
