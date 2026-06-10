<?php

declare(strict_types=1);

use Ions\View\ViewFactory;

/*
|--------------------------------------------------------------------------
| AssetExtension registration in the shared Twig environment (Phase 9.5)
|--------------------------------------------------------------------------
| Renders inline templates through the SAME environment ViewFactory hands to
| every view render, proving vite()/asset() are available host-wide without
| any registration step. The fixture app has no public/build or public/hot,
| so vite() exercises the never-throw fallback here.
*/

beforeEach(function () {
    bootFixtureKernel();
});

test('vite() and asset() are callable from templates rendered by the shared env', function () {
    $env = (new ViewFactory())->make();

    $out = $env->createTemplate("{{ vite('resources/js/app.js') }}|{{ asset('css/app.css') }}")->render([]);

    // The raw `<!--` proves vite() output is html-safe (autoescape would
    // have emitted &lt;!-- otherwise); the URL proves asset() resolution.
    expect($out)->toContain('<!-- vite: manifest not found')
        ->and($out)->toContain('|http://localhost/css/app.css');
});

test('asset() appends the cache-buster for a real file under the fixture public/', function () {
    $env = (new ViewFactory())->make();
    $file = \Ions\Bundles\Path::public('asset-fixture.css');
    file_put_contents($file, 'body{}');

    try {
        $out = $env->createTemplate("{{ asset('asset-fixture.css') }}")->render([]);

        expect($out)->toBe('http://localhost/asset-fixture.css?v=' . filemtime($file));
    } finally {
        @unlink($file);
    }
});
