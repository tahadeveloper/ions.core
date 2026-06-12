<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Ions\Http\FormRequest;

/**
 * Validates a registration submission. A failed validation on a web (HTML)
 * request redirects back with the errors + old input flashed (FormRequest +
 * ExceptionHandler, 10.3); the unique check runs against the users table via
 * the framework's database presence verifier.
 */
final class RegisterRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
