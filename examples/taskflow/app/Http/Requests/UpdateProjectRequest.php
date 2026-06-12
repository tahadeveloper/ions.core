<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Ions\Http\FormRequest;

/**
 * Validates a project edit. Same shape as {@see StoreProjectRequest}; the
 * controller performs the per-record `update` policy check before applying it.
 */
final class UpdateProjectRequest extends FormRequest
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
