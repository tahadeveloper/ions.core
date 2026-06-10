<?php

declare(strict_types=1);

namespace Ions\Http;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base API resource: shapes a single model/array into a typed response payload.
 *
 * Extend it and implement {@see toArray()} to declare the public shape of a
 * resource. {@see toResponse()} emits a single-envelope JSON response whose
 * top-level body is exactly the output of {@see wrap()} — no double-nesting.
 *
 * Usage:
 *   UserResource::make($user)              // single resource
 *   UserResource::collection($users)       // a ResourceCollection
 *
 * @phpstan-consistent-constructor
 */
abstract class Resource implements Responsable
{
    /**
     * The key the resource data is nested under. Set to null to disable wrapping.
     */
    protected ?string $wrap = 'data';

    /**
     * @param mixed $resource The model, array or stdClass being transformed.
     */
    public function __construct(protected mixed $resource)
    {
    }

    /**
     * Transform the underlying resource into an array.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(Request $request): array;

    /**
     * Build a single resource instance.
     */
    public static function make(mixed $resource): static
    {
        return new static($resource);
    }

    /**
     * Build a {@see ResourceCollection} that maps each item through this resource.
     *
     * @param iterable<mixed>|\Illuminate\Contracts\Pagination\LengthAwarePaginator<int, mixed> $items
     */
    public static function collection(mixed $items): ResourceCollection
    {
        return new ResourceCollection($items, static::class);
    }

    /**
     * Disable the data wrapping for this resource.
     */
    public function withoutWrapping(): static
    {
        $this->wrap = null;

        return $this;
    }

    /**
     * Set the wrapping key for this resource.
     */
    public function wrappedBy(?string $key): static
    {
        $this->wrap = $key;

        return $this;
    }

    /**
     * Read a value from the underlying resource regardless of whether it is an
     * array, an object (e.g. an Eloquent model / stdClass) or implements
     * ArrayAccess.
     */
    protected function get(string $key, mixed $default = null): mixed
    {
        $resource = $this->resource;

        if (is_array($resource) || $resource instanceof \ArrayAccess) {
            return $resource[$key] ?? $default;
        }

        if (is_object($resource)) {
            return $resource->{$key} ?? $default;
        }

        return $default;
    }

    /**
     * Wrap the transformed array under the configured key.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function wrap(array $data): array
    {
        return $this->wrap === null ? $data : [$this->wrap => $data];
    }

    public function toResponse(Request $request): Response
    {
        return new JsonResponse(
            $this->wrap($this->toArray($request)),
            Response::HTTP_OK,
        );
    }
}
