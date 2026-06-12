<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Task;
use Ions\Http\FormRequest;

/**
 * Validates a task edit. Same shape as {@see StoreTaskRequest}; the controller
 * authorizes `update` against the parent project's policy first.
 */
final class UpdateTaskRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', 'in:' . implode(',', Task::STATUSES)],
        ];
    }
}
