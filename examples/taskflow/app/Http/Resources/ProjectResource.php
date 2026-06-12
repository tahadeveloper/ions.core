<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Project;
use Ions\Http\Resource;
use Ions\Support\Request;

/**
 * JSON shape for a project (7.7 Resource): a single `data` envelope. Used both
 * for a single project (ProjectResource::make) and as the item resource for a
 * paginated collection (ProjectResource::collection, which adds meta + links).
 *
 * `note` is stored encrypted at rest (13.6); since this Resource is only ever
 * returned to an authorized user (the API actions authorize first), it exposes
 * the DECRYPTED note via Project::noteText() (null on a DecryptException).
 */
final class ProjectResource extends Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $note = $this->resource instanceof Project
            ? $this->resource->noteText()
            : $this->get('note');

        return [
            'id' => $this->get('id'),
            'name' => $this->get('name'),
            'note' => $note,
            'owner_id' => $this->get('owner_id'),
        ];
    }
}
