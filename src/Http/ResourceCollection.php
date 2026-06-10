<?php

declare(strict_types=1);

namespace Ions\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps a collection / array / paginator and maps each item through a
 * {@see Resource} class. When given a {@see LengthAwarePaginator} the rendered
 * payload carries pagination `meta` and `links`.
 */
class ResourceCollection implements Responsable
{
    /**
     * The key the collection data is nested under. Set to null to disable wrapping.
     */
    protected ?string $wrap = 'data';

    /**
     * @param iterable<mixed>|LengthAwarePaginator<int, mixed> $items
     * @param class-string<Resource> $resourceClass
     */
    public function __construct(
        protected mixed $items,
        protected string $resourceClass,
    ) {
    }

    /**
     * Disable the data wrapping for this collection.
     */
    public function withoutWrapping(): static
    {
        $this->wrap = null;

        return $this;
    }

    public function toResponse(Request $request): Response
    {
        $payload = $this->resolvePayload($request);

        return Json::ok($payload);
    }

    /**
     * Build the payload passed to {@see Json::ok()}. A wrapped collection is an
     * associative array (data/meta/links); an unwrapped one is the bare list.
     *
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    protected function resolvePayload(Request $request): array
    {
        if ($this->items instanceof LengthAwarePaginator) {
            $mapped = $this->mapItems($this->items->items(), $request);
            $key = $this->wrap ?? 'data';

            return [
                $key => $mapped,
                'meta' => [
                    'current_page' => $this->items->currentPage(),
                    'last_page' => $this->items->lastPage(),
                    'per_page' => $this->items->perPage(),
                    'total' => $this->items->total(),
                ],
                'links' => [
                    'first' => $this->items->url(1),
                    'last' => $this->items->url($this->items->lastPage()),
                    'prev' => $this->items->previousPageUrl(),
                    'next' => $this->items->nextPageUrl(),
                ],
            ];
        }

        $mapped = $this->mapItems($this->normalizeItems($this->items), $request);

        if ($this->wrap === null) {
            return $mapped;
        }

        return [$this->wrap => $mapped];
    }

    /**
     * @param iterable<mixed> $items
     * @return list<array<string, mixed>>
     */
    protected function mapItems(iterable $items, Request $request): array
    {
        $resourceClass = $this->resourceClass;
        $out = [];

        foreach ($items as $item) {
            /** @var Resource $resource */
            $resource = new $resourceClass($item);
            $out[] = $resource->toArray($request);
        }

        return $out;
    }

    /**
     * Normalize an arbitrary item set (array, Illuminate Collection, …) into an
     * iterable list.
     *
     * @return iterable<mixed>
     */
    protected function normalizeItems(mixed $items): iterable
    {
        if (is_array($items) || $items instanceof \Traversable) {
            return $items;
        }

        if (is_object($items) && method_exists($items, 'all')) {
            /** @var iterable<mixed> $all */
            $all = $items->all();
            return $all;
        }

        return [];
    }
}
