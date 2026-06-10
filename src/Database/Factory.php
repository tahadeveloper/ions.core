<?php

declare(strict_types=1);

namespace Ions\Database;

use Closure;
use Faker\Generator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RuntimeException;

/**
 * Minimal model factory for tests and seeding.
 *
 * Subclasses set `protected string $model` (FQCN) and implement
 * `definition(): array` returning attribute defaults — plain values or
 * closures evaluated once per built instance. Closures receive the
 * partially-built attributes array (keys declared earlier are already
 * resolved; later keys may still be closures).
 *
 * Attribute precedence (last wins): definition() < state() (in the order
 * stacked) < the overrides passed to make()/create().
 *
 * Faker: `$this->faker` is a lazily-created, per-factory-instance memoized
 * Faker generator. fakerphp/faker is a require-dev dependency of the
 * framework (mirroring Laravel); hosts that want faker in their factories
 * must `composer require --dev fakerphp/faker` themselves — accessing
 * `$this->faker` without it throws a RuntimeException with that hint.
 *
 * Scope (deliberate): no sequence() and no relationship support
 * (has/for/afterCreating). Build related models explicitly:
 * `Post::factory()->create(['user_id' => User::factory()->create()->id])`.
 *
 * @template TModel of Model
 *
 * @property-read Generator $faker Lazily-memoized Faker generator.
 */
abstract class Factory
{
    /**
     * Fully-qualified class name of the Eloquent model this factory builds.
     *
     * @var class-string<TModel>
     */
    protected string $model;

    /** How many instances make()/create() build. */
    protected int $count = 1;

    /**
     * Stacked state transformations applied over the definition, in order.
     *
     * @var list<array<string, mixed>|Closure(array<string, mixed>): array<string, mixed>>
     */
    protected array $states = [];

    /** Memoized per-factory Faker generator (lazy; see $faker). */
    private ?Generator $fakerInstance = null;

    /** Factories are configured via properties; final keeps new static() safe. */
    final public function __construct()
    {
    }

    /**
     * The model's default attribute values.
     *
     * @return array<string, mixed>
     */
    abstract protected function definition(): array;

    /** Create a fresh factory instance. */
    public static function new(): static
    {
        // phpstan cannot infer TModel for `new static()` on the abstract
        // class; concrete subclasses bind it via their $model property.
        /** @phpstan-ignore return.type */
        return new static();
    }

    /**
     * Return a clone configured to build $count instances.
     */
    public function count(int $count): static
    {
        if ($count < 1) {
            throw new LogicException('Factory count must be at least 1, got ' . $count);
        }

        $clone = clone $this;
        $clone->count = $count;

        return $clone;
    }

    /**
     * Return a clone with an additional state layered on top.
     *
     * Array states are merged over the current attributes; Closure states
     * receive the current attributes array and return the overrides to merge.
     * Only \Closure instances are invoked — a plain array is always treated
     * as attributes, even when it happens to satisfy is_callable() (e.g.
     * ['Foo', 'method']).
     *
     * @param array<string, mixed>|Closure(array<string, mixed>): array<string, mixed> $state
     */
    public function state(array|Closure $state): static
    {
        $clone = clone $this;
        $clone->states[] = $state;

        return $clone;
    }

    /**
     * Build unsaved model instance(s).
     *
     * Returns a single model when count is 1 (the default), otherwise an
     * Eloquent collection.
     *
     * @param array<string, mixed> $overrides Highest-precedence attributes.
     *
     * @return TModel|Collection<int, TModel>
     */
    public function make(array $overrides = []): Model|Collection
    {
        if ($this->count === 1) {
            return $this->makeOne($overrides);
        }

        $models = [];
        for ($i = 0; $i < $this->count; $i++) {
            $models[] = $this->makeOne($overrides);
        }

        return new Collection($models);
    }

    /**
     * Build and persist model instance(s).
     *
     * @param array<string, mixed> $overrides Highest-precedence attributes.
     *
     * @return TModel|Collection<int, TModel>
     */
    public function create(array $overrides = []): Model|Collection
    {
        $result = $this->make($overrides);

        if ($result instanceof Collection) {
            foreach ($result as $model) {
                $model->save();
            }

            return $result;
        }

        $result->save();

        return $result;
    }

    /**
     * Build a single unsaved model from the resolved attributes.
     *
     * @param array<string, mixed> $overrides
     *
     * @return TModel
     */
    protected function makeOne(array $overrides): Model
    {
        $model = new $this->model();
        $model->forceFill($this->resolveAttributes($overrides));

        return $model;
    }

    /**
     * Merge definition + states + overrides, then evaluate closure values.
     *
     * Called once per instance so closures (randomness, faker) produce fresh
     * values for every model built.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function resolveAttributes(array $overrides): array
    {
        $attributes = $this->definition();

        foreach ($this->states as $state) {
            $patch = $state instanceof Closure ? $state($attributes) : $state;
            $attributes = array_merge($attributes, $patch);
        }

        $attributes = array_merge($attributes, $overrides);

        // Final pass in declaration order: each closure receives the
        // attributes resolved so far (earlier keys evaluated, later keys raw).
        foreach ($attributes as $key => $value) {
            if ($value instanceof Closure) {
                $attributes[$key] = $value($attributes);
            }
        }

        return $attributes;
    }

    /** Lazy accessor backing the magic $faker property. */
    protected function faker(): Generator
    {
        if (!class_exists(\Faker\Factory::class)) {
            throw new RuntimeException(
                'Faker is not installed. Run `composer require --dev fakerphp/faker` to use $this->faker in factories.'
            );
        }

        return $this->fakerInstance ??= \Faker\Factory::create();
    }

    public function __get(string $name): mixed
    {
        if ($name === 'faker') {
            return $this->faker();
        }

        throw new LogicException(sprintf('Undefined property %s::$%s', static::class, $name));
    }
}
