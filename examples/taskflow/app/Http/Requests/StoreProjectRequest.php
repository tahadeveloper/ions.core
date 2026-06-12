<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Ions\Http\FormRequest;

/**
 * Validates a new-project submission. A web validation failure redirects back
 * with errors + old input flashed (FormRequest + ExceptionHandler, 10.3); a
 * JSON/api failure renders a 422 bag. authorize() stays true here — the
 * "create-project" ability + per-record policy checks live in the controller
 * (the create ability gate is enforced by the verified-only route + the Gate).
 */
final class StoreProjectRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
