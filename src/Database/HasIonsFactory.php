<?php

declare(strict_types=1);

namespace Ions\Database;

use RuntimeException;

/**
 * Gives an Eloquent model a static factory() entry point.
 *
 * Resolution order (first existing factory class wins):
 *
 *   1. A `protected static string $factory = SomeFactory::class;` property
 *      on the model, when present (explicit override).
 *   2. `Database\Factories\{Model}Factory` — the Laravel-standard top-level
 *      namespace (since 4.4, first-choice convention). E.g. `App\Models\Widget`
 *      resolves `Database\Factories\WidgetFactory`. Requires the host to map
 *      `"Database\\": "database/"` in composer.json.
 *   3. `{ModelNamespace}\Factories\{Model}Factory` — the 4.2 convention,
 *      kept as a backwards-compatible fallback. E.g. `App\Widget` resolves
 *      `App\Factories\WidgetFactory`.
 *
 * Steps 2 and 3 are probed with class_exists(); the first hit is used. When
 * none resolve, factory() throws with the candidates it looked for.
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
     * The factory class name for this model.
     *
     * Honours the explicit static $factory property first, then probes the
     * Laravel-standard `Database\Factories\{Model}Factory` (first-choice) and
     * the legacy `{ModelNamespace}\Factories\{Model}Factory` (BC fallback),
     * returning the first that exists. When neither exists, the BC candidate is
     * returned so the failure message names a concrete expectation.
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

        // (2) Laravel-parity first choice: top-level Database\Factories\…
        $standard = 'Database\\Factories\\' . $basename . 'Factory';
        if (class_exists($standard)) {
            return $standard;
        }

        // (3) 4.2 convention fallback: {ModelNamespace}\Factories\…
        return $namespace . 'Factories\\' . $basename . 'Factory';
    }
}
