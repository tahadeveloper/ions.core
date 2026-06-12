<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Ions\Http\FormRequest;

/**
 * Validates a login submission (shape only — the credential check lives in the
 * controller so a wrong password yields a friendly "invalid credentials"
 * message rather than a field error).
 */
final class LoginRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
