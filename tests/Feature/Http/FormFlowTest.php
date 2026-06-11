<?php

declare(strict_types=1);

use Ions\Bundles\Path;
use Ions\Bundles\Route;
use Ions\Foundation\Kernel;
use Ions\Http\FormRequest;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| Web form round-trip: FormRequest failure → 302 back + errors/old (10.3)
|--------------------------------------------------------------------------
| The fixture session driver is 'array' and Kernel::handle() does NOT renew
| the session between sequential calls in one process, so flash written by
| request 1 is visible to request 2 and consumed by its render — gone by
| request 3. CSRF is disabled per-test to focus on the validation flow.
*/

class ContactFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'email' => 'required|email',
        ];
    }
}

beforeEach(function () {
    bootFixtureKernel();
    config([
        'app.csrf.enabled' => false,
        'app.twig.source' => Path::root('views'),
        'app.auth.public_paths' => array_merge(
            (array) config('app.auth.public_paths', []),
            ['/api/contact'],
        ),
    ]);

    Route::post('/contact', function (Request $request) {
        ContactFormRequest::validate($request);

        return new Response('saved');
    });
    Route::get('/contact', fn () => view('form.contact'));
    Route::post('/api/contact', function (Request $request) {
        ContactFormRequest::validate($request);

        return new Response('saved');
    });
});

afterEach(fn () => Kernel::resetForTesting());

test('an invalid web POST redirects back with errors and input flashed; the flash survives exactly one render', function () {
    // Request 1: invalid POST (missing email) from the form page.
    $post = Request::create('/contact', 'POST', ['name' => 'Ada'], [], [], [
        'HTTP_REFERER' => 'http://localhost/contact',
    ]);
    $r1 = Kernel::handle($post);

    expect($r1->getStatusCode())->toBe(302)
        ->and($r1->headers->get('Location'))->toBe('http://localhost/contact');

    // Request 2: the form re-render sees errors() and old().
    $r2 = Kernel::handle(Request::create('/contact'));
    $body2 = (string) $r2->getContent();

    expect($r2->getStatusCode())->toBe(200)
        ->and($body2)->toContain('old:Ada')
        ->and($body2)->not->toContain('errors:none');

    // Request 3: the flash was consumed — errors/old are gone.
    $r3 = Kernel::handle(Request::create('/contact'));
    $body3 = (string) $r3->getContent();

    expect($body3)->toContain('errors:none')
        ->and($body3)->toContain('old:empty');
});

test('a missing Referer falls back to a root redirect', function () {
    $r = Kernel::handle(Request::create('/contact', 'POST', ['name' => 'Ada']));

    expect($r->getStatusCode())->toBe(302)
        ->and($r->headers->get('Location'))->toBe('http://localhost/');
});

test('password fields are never flashed as old input', function () {
    Route::post('/secure', function (Request $request) {
        ContactFormRequest::validate($request);

        return new Response('saved');
    });

    Kernel::handle(Request::create('/secure', 'POST', [
        'name' => 'Ada',
        'password' => 'super-secret',
    ], [], [], ['HTTP_REFERER' => 'http://localhost/secure']));

    expect(old('name'))->toBe('Ada')
        ->and(old('password'))->toBeNull();
});

test('a valid web POST is unaffected by the redirect path', function () {
    $r = Kernel::handle(Request::create('/contact', 'POST', [
        'name' => 'Ada',
        'email' => 'ada@example.com',
    ]));

    expect($r->getStatusCode())->toBe(200)
        ->and($r->getContent())->toBe('saved');
});

test('an invalid API POST keeps the 422 JSON contract (pinned shape)', function () {
    $r = Kernel::handle(Request::create('/api/contact', 'POST', ['name' => 'Ada']));
    $payload = json_decode((string) $r->getContent(), true);

    expect($r->getStatusCode())->toBe(422)
        ->and(array_keys($payload))->toBe(['status', 'message', 'code', 'errors'])
        ->and($payload['status'])->toBe('error')
        ->and($payload['code'])->toBe(422)
        ->and($payload['errors'])->toHaveKey('email');
});

test('a web POST that wants JSON keeps the 422 JSON response', function () {
    $r = Kernel::handle(Request::create('/contact', 'POST', ['name' => 'Ada'], [], [], [
        'HTTP_ACCEPT' => 'application/json',
    ]));
    $payload = json_decode((string) $r->getContent(), true);

    expect($r->getStatusCode())->toBe(422)
        ->and($payload['status'])->toBe('error')
        ->and($payload['errors'])->toHaveKey('email');
});
