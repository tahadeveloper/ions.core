<?php

declare(strict_types=1);

use Ions\Bundles\Route;
use Ions\Http\ErrorBag;
use Ions\Http\RedirectResponse;
use Ions\Http\Redirector;
use Ions\Http\ResponseNormalizer;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| Fluent redirect() + flash/old/errors helpers (Phase 10.3)
|--------------------------------------------------------------------------
| Flash writes happen immediately (session is array-backed in the fixture);
| reads are memoized per request, so write-then-read within one test works.
*/

beforeEach(fn () => bootFixtureKernel());

afterEach(fn () => \Ions\Foundation\Kernel::resetForTesting());

test('redirect($to) returns a 302 RedirectResponse targeting app_url + path', function () {
    $response = redirect('/dashboard');

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getStatusCode())->toBe(302)
        ->and($response->getTargetUrl())->toBe('http://localhost/dashboard');
});

test('redirect($to, $status) honours the status code', function () {
    expect(redirect('/moved', 301)->getStatusCode())->toBe(301);
});

test('redirect() with no arguments returns a Redirector builder', function () {
    expect(redirect())->toBeInstanceOf(Redirector::class);
});

test('redirect()->to() passes absolute URLs through unchanged', function () {
    expect(redirect()->to('https://other.example/path')->getTargetUrl())
        ->toBe('https://other.example/path');
});

test('redirect()->route() resolves a named route with params and query leftovers', function () {
    Route::get('/profile/{id}', fn () => new Response('x'), [], 'profile.show');

    $response = redirect()->route('profile.show', ['id' => 7, 'tab' => 'info']);

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getTargetUrl())->toBe('http://localhost/profile/7?tab=info');
});

test('the route() helper returns the URL for a named route', function () {
    Route::get('/profile/{id}', fn () => new Response('x'), [], 'profile.show');

    expect(route('profile.show', ['id' => 3]))->toBe('http://localhost/profile/3');
});

test('redirect()->away() never prefixes app_url', function () {
    expect(redirect()->away('https://example.com/external')->getTargetUrl())
        ->toBe('https://example.com/external');
});

test('back() falls back when the request has no Referer', function () {
    expect(back('/home')->getTargetUrl())->toBe('http://localhost/home');
});

test('back() follows the Referer header when present', function () {
    $request = Request::create('/somewhere', 'GET', [], [], [], ['HTTP_REFERER' => 'http://localhost/origin']);

    expect((new Redirector($request))->back()->getTargetUrl())->toBe('http://localhost/origin');
});

test('with() flashes a key/value readable via flash()', function () {
    redirect('/x')->with('status', 'saved');

    expect(flash('status'))->toBe('saved')
        // memoized within the same request — repeat reads agree
        ->and(flash('status'))->toBe('saved');
});

test('with() accepts an array of flash values', function () {
    redirect('/x')->with(['a' => 1, 'b' => 'two']);

    expect(flash('a'))->toBe(1)->and(flash('b'))->toBe('two');
});

test('flash($key, $value) sets and flash($key) reads; unset keys read null', function () {
    flash('note', 'hello');

    expect(flash('note'))->toBe('hello')
        ->and(flash('unset-key'))->toBeNull();
});

test('withErrors() flashes an ErrorBag readable via errors()', function () {
    redirect('/x')->withErrors(['email' => 'Invalid email.']);

    $bag = errors();
    expect($bag)->toBeInstanceOf(ErrorBag::class)
        ->and($bag->any())->toBeTrue()
        ->and($bag->first('email'))->toBe('Invalid email.');
});

test('errors() always returns a bag — empty when nothing was flashed', function () {
    expect(errors())->toBeInstanceOf(ErrorBag::class)
        ->and(errors()->any())->toBeFalse();
});

test('withInput() flashes request input for old(), excluding password fields', function () {
    $request = Request::create('/contact', 'POST', [
        'name' => 'Ada',
        'password' => 'secret',
        'password_confirmation' => 'secret',
    ]);

    (new Redirector($request))->to('/contact')->withInput();

    expect(old('name'))->toBe('Ada')
        ->and(old('password'))->toBeNull()
        ->and(old('password_confirmation'))->toBeNull()
        ->and(old('missing', 'fallback'))->toBe('fallback');
});

test('withInput($only) restricts the flashed keys', function () {
    $request = Request::create('/contact', 'POST', ['name' => 'Ada', 'email' => 'a@b.c']);

    (new Redirector($request))->to('/contact')->withInput(['name']);

    expect(old('name'))->toBe('Ada')->and(old('email'))->toBeNull();
});

test('app.forms.dont_flash overrides the excluded input keys', function () {
    config(['app.forms.dont_flash' => ['ssn']]);
    $request = Request::create('/x', 'POST', ['ssn' => '123', 'password' => 'now-flashed']);

    (new Redirector($request))->to('/x')->withInput();

    expect(old('ssn'))->toBeNull()->and(old('password'))->toBe('now-flashed');
});

test('withHeaders() sets headers on the redirect response', function () {
    $response = redirect('/x')->withHeaders(['X-Reason' => 'validation']);

    expect($response->headers->get('X-Reason'))->toBe('validation');
});

test('the fluent RedirectResponse passes through ResponseNormalizer untouched', function () {
    $response = redirect('/x');

    expect(ResponseNormalizer::normalize($response, Request::create('/')))->toBe($response);
});

test('the legacy static Redirect API is untouched (BC)', function () {
    foreach (['home', 'back', 'refresh', 'away', 'internal'] as $method) {
        expect(method_exists(\Ions\Bundles\Redirect::class, $method))->toBeTrue();
    }
});
