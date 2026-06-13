<?php

declare(strict_types=1);

namespace Ions\Console;

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Command;
use Illuminate\Events\Dispatcher;
use Ions\Bundles\Path;
use Ions\Foundation\Kernel as FoundationKernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Console Kernel — first-class console support for the Ions framework.
 *
 * Wraps an {@see ConsoleApplication} (Symfony Console under the hood) and:
 *  - boots the framework container so commands resolve config(), db, session, …;
 *  - discovers and registers the framework commands shipped in src/commands;
 *  - registers host commands declared in config('console.commands') and any
 *    classes auto-discovered from the host {app|src}/Commands directory.
 *
 * The bin/ions entry point creates the Kernel and calls run().
 */
class Kernel
{
    private ConsoleApplication $application;

    /**
     * Fully-qualified class names of the framework commands shipped under
     * src/commands. They live in the global namespace (classmap autoloaded),
     * so they are referenced here by their unqualified class name.
     *
     * @var list<class-string>
     */
    private const FRAMEWORK_COMMANDS = [
        \KeyCommand::class,
        \ModelCommand::class,
        \ProviderCommand::class,
        \Ions\commands\RouteListCommand::class,
        \SeederCommand::class,
        \MakeMiddlewareCommand::class,
        \MakeServiceProviderCommand::class,
        \MakeCommandCommand::class,
        \MakeResourceCommand::class,
        \MakeRequestCommand::class,
        \MakeJobCommand::class,
        \MakeEventCommand::class,
        \MakeListenerCommand::class,
        \MakeTestCommand::class,
        \MakeFactoryCommand::class,
        \ControllerCommand::class,
        \MigrateCommand::class,
        \SeedCommand::class,
        \RollBackCommand::class,
        \SchemaCommand::class,
        \DumpCommand::class,
        \SuperCommand::class,
        \InstallVueCommand::class,
        \InstallAssetsCommand::class,
        \ScheduleRunCommand::class,
        \ScheduleListCommand::class,
        \QueueWorkCommand::class,
        \QueueFailedCommand::class,
        \QueueRetryCommand::class,
        \QueueForgetCommand::class,
        \QueueFlushCommand::class,
        \OpenApiCommand::class,
        \Ions\commands\RouteCacheCommand::class,
        \Ions\commands\RouteClearCommand::class,
        \Ions\commands\ConfigCacheCommand::class,
        \Ions\commands\ConfigClearCommand::class,
        \Ions\commands\DiscoverCacheCommand::class,
        \Ions\commands\DiscoverClearCommand::class,
        \Ions\commands\OptimizeCommand::class,
        \Ions\commands\OptimizeClearCommand::class,
        \Ions\commands\CacheClearResponsesCommand::class,
        \Ions\commands\PreloadGenerateCommand::class,
        \Ions\commands\DoctorCommand::class,
        \Ions\commands\DownCommand::class,
        \Ions\commands\UpCommand::class,
        \Ions\commands\ServeCommand::class,
    ];

    private function __construct(ConsoleApplication $application)
    {
        $this->application = $application;
    }

    /**
     * Boot the framework container and build a fully-wired console application.
     *
     * @param string|null $basePath Optional host-app root (used by tests/CLI to
     *                              boot against a fixture directory). When omitted
     *                              the framework's default path resolution applies.
     */
    public static function boot(?string $basePath = null): self
    {
        FoundationKernel::boot($basePath);

        return self::forBootedKernel();
    }

    /**
     * Build a fully-wired console kernel against the ALREADY-booted framework
     * container — without re-booting it. Used by in-request callers (e.g. the
     * /cron/schedule web cron running a command task) where a re-boot would
     * reset live request state.
     */
    public static function forBootedKernel(): self
    {
        $container = new ConsoleContainerProxy(FoundationKernel::app());

        $application = new ConsoleApplication($container, new Dispatcher($container), '1.0.0');
        $application->setName('Ions Console');
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);

        $kernel = new self($application);
        $kernel->registerCommands();

        return $kernel;
    }

    /**
     * The underlying Illuminate console application.
     */
    public function getApplication(): ConsoleApplication
    {
        return $this->application;
    }

    /**
     * Run the console application.
     *
     * @param list<string>|null $argv When null the global $argv is used.
     */
    public function run(?array $argv = null): int
    {
        $input = $argv === null ? new ArgvInput() : new ArgvInput(array_values($argv));

        return $this->application->run($input, new ConsoleOutput());
    }

    /**
     * Register the framework commands and the host application's commands.
     */
    private function registerCommands(): void
    {
        foreach (self::FRAMEWORK_COMMANDS as $class) {
            $this->addCommandClass($class);
        }

        foreach ($this->hostCommandClasses() as $class) {
            $this->addCommandClass($class);
        }
    }

    /**
     * Resolve every host command class: the explicit config('console.commands')
     * list plus any classes auto-discovered in the host {app|src}/Commands dir.
     *
     * @return list<class-string>
     */
    private function hostCommandClasses(): array
    {
        $classes = [];

        /** @var array<int, mixed> $configured */
        $configured = (array) config('console.commands', []);
        foreach ($configured as $class) {
            if (is_string($class) && $class !== '') {
                $classes[] = $class;
            }
        }

        foreach ($this->discoverHostCommandClasses() as $class) {
            $classes[] = $class;
        }

        /** @var list<class-string> $unique */
        $unique = array_values(array_unique($classes));

        return $unique;
    }

    /**
     * Auto-discover command classes from the host application's Commands folder.
     * Classes are matched by namespace declaration, so they work regardless of
     * whether the host uses a src/ or app/ layout.
     *
     * @return list<class-string>
     */
    private function discoverHostCommandClasses(): array
    {
        $dir = Path::src('Commands');
        if (!is_dir($dir)) {
            return [];
        }

        $classes = [];
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        foreach ($files as $file) {
            $class = $this->classFromFile($file);
            if ($class !== null && class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Derive the fully-qualified class name from a PHP file by reading its
     * namespace and class declarations.
     *
     * @return class-string|null
     */
    private function classFromFile(string $file): ?string
    {
        $contents = (string) file_get_contents($file);

        $namespace = '';
        if (preg_match('/^\s*namespace\s+([^;]+);/m', $contents, $m) === 1) {
            $namespace = trim($m[1]) . '\\';
        }

        if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $contents, $m) === 1) {
            /** @var class-string $fqcn */
            $fqcn = $namespace . $m[1];

            return $fqcn;
        }

        return null;
    }

    /**
     * Instantiate and register a command class, skipping anything that is not a
     * concrete Illuminate console command.
     *
     * @param class-string $class
     */
    private function addCommandClass(string $class): void
    {
        if (!class_exists($class)) {
            return;
        }

        if (!is_subclass_of($class, Command::class)) {
            return;
        }

        $reflection = new \ReflectionClass($class);
        if ($reflection->isAbstract()) {
            return;
        }

        /** @var Command $command */
        $command = new $class();
        $this->application->add($command);
    }

    /**
     * Path to the framework commands directory (src/commands).
     */
    public static function frameworkCommandsPath(): string
    {
        return Path::core('commands');
    }
}
