<?php

use Illuminate\Validation\ValidationException;
use Ions\Http\ExceptionHandler;
use Ions\Http\FormRequest;
use Ions\Support\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    \Ions\Foundation\Kernel::boot(__DIR__ . '/../../fixtures/app');
});

afterEach(function () {
    \Ions\Foundation\Kernel::resetForTesting();
});

class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'email' => 'required|email',
        ];
    }
}

class ForbiddenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return ['name' => 'required'];
    }
}

test('validated() returns the data for valid input', function () {
    $request = Request::create('/api/users', 'POST', [
        'name' => 'Ada',
        'email' => 'ada@example.com',
    ]);

    $validated = StoreUserRequest::validate($request);

    expect($validated)->toBe(['name' => 'Ada', 'email' => 'ada@example.com']);
});

test('invalid input throws a ValidationException that maps to 422 with errors', function () {
    $request = Request::create('/api/users', 'POST', ['name' => 'Ada']); // missing email

    $thrown = null;
    try {
        StoreUserRequest::validate($request);
    } catch (ValidationException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(ValidationException::class);

    // ExceptionHandler renders it as 422 JSON with an errors bag for API requests.
    $apiRequest = Request::create('/api/users', 'POST');
    $response = (new ExceptionHandler())->render($thrown, $apiRequest);
    $payload = json_decode((string) $response->getContent(), true);

    expect($response->getStatusCode())->toBe(422)
        ->and($payload['status'])->toBe('error')
        ->and($payload['errors'])->toHaveKey('email');
});

test('authorize() returning false yields a 403', function () {
    $request = Request::create('/api/users', 'POST', ['name' => 'Ada']);

    $thrown = null;
    try {
        ForbiddenRequest::validate($request);
    } catch (HttpException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(HttpException::class)
        ->and($thrown->getStatusCode())->toBe(403);

    $response = (new ExceptionHandler())->render($thrown, Request::create('/api/users', 'POST'));
    expect($response->getStatusCode())->toBe(403);
});
