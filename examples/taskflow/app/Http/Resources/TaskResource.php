<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Ions\Http\Resource;
use Ions\Support\Request;

/**
 * JSON shape for a task (7.7 Resource): a single `data` envelope.
 */
final class TaskResource extends Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->get('id'),
            'project_id' => $this->get('project_id'),
            'title' => $this->get('title'),
            'description' => $this->get('description'),
            'status' => $this->get('status'),
            'assignee_id' => $this->get('assignee_id'),
        ];
    }
}
