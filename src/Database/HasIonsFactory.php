<?php

declare(strict_types=1);

namespace Ions\Database;

use RuntimeException;

/**
 * Gives an Eloquent model a static factory() entry point.
 *
 * Resolution convention (deliberately simpler than Laravel's
 * Database\Factories cross-namespace mapping):
 *
 *   1. A `protected static string $factory = SomeFactory::class;` property
 *      on the model wins, when present.
 *   2. Otherwise `{ModelNamespace}\Factories\{Model}Factory` — e.g.
 *      `App\Widget` resolves `App\Factories\WidgetFactory`.
 */
trait HasIonsFactory
{
    /**
     * Resolve and instantiate this model's factory.
     *
     * @return Factory<static>
     */
    public static function factory(): Factory
    {
        $class = static::factoryClass();

        if (!class_exists($class) || !is_a($class, Factory::class, true)) {
            throw new RuntimeException(sprintf(
                'No factory found for model [%s]: expected [%s] (extending %s), or set a static $factory property on the model.',
                static::class,
                $class,
                Factory::class
            ));
        }

        /** @var Factory<static> */
        return $class::new();
    }

    /**
     * The factory class name for this model (override point: static $factory).
     *
     * @return class-string<Factory<static>>|string
     */
    protected static function factoryClass(): string
    {
        $vars = get_class_vars(static::class);

        if (isset($vars['factory']) && is_string($vars['factory']) && $vars['factory'] !== '') {
            return $vars['factory'];
        }

        $model = static::class;
        $separator = strrpos($model, '\\');

        $namespace = $separator === false ? '' : substr($model, 0, $separator) . '\\';
        $basename = $separator === false ? $model : substr($model, $separator + 1);

        return $namespace . 'Factories\\' . $basename . 'Factory';
    }
}
