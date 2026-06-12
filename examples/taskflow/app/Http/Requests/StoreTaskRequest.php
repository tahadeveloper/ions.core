<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Task;
use Ions\Http\FormRequest;

/**
 * Validates a new task. `status` must be one of {@see Task::STATUSES}
 * (todo|doing|done); `attachment` is an optional uploaded file — the actual
 * magic-bytes + extension allow-list enforcement happens in IonUpload::store()
 * at storage time (a malicious extension is rejected there, never written).
 */
final class StoreTaskRequest extends FormRequest
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
