<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Ions\Http\Resource;
use Ions\Support\Request;

/**
 * JSON shape for a project (7.7 Resource): a single `data` envelope. Used both
 * for a single project (ProjectResource::make) and as the item resource for a
 * paginated collection (ProjectResource::collection, which adds meta + links).
 */
final class ProjectResource extends Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->get('id'),
            'name' => $this->get('name'),
            'note' => $this->get('note'),
            'owner_id' => $this->get('owner_id'),
        ];
    }
}
