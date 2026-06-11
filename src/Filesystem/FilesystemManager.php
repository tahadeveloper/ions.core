<?php

declare(strict_types=1);

namespace Ions\Filesystem;

use Aws\S3\S3Client;
use Closure;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;

/**
 * Config-driven Flysystem driver manager.
 *
 * Resolves named disks from config('filesystem.disks'), each entry being
 * ['driver' => 'local'|'s3'|'ftp'|'sftp'|'memory', ...driver opts]. The driver
 * registry is extensible via {@see self::extend()}.
 */
class FilesystemManager
{
    /**
     * Resolved disks cached per name.
     *
     * @var array<string, Filesystem>
     */
    private array $disks = [];

    /**
     * Custom driver factories registered via extend().
     *
     * @var array<string, Closure(array<string, mixed>): FilesystemAdapter>
     */
    private array $customCreators = [];

    /**
     * Disk names whose instances were injected via {@see set()} (e.g. by
     * Storage::fake()) rather than resolved from configuration.
     *
     * @var array<string, true>
     */
    private array $overridden = [];

    /**
     * Resolve a named disk (defaults to config('filesystem.default')).
     */
    public function disk(?string $name = null): Filesystem
    {
        $name ??= $this->getDefaultDriver();

        return $this->disks[$name] ??= $this->resolve($name);
    }

    /**
     * Register a custom driver factory. The factory receives the disk config
     * array and returns a Flysystem adapter.
     *
     * @param Closure(array<string, mixed>): FilesystemAdapter $factory
     */
    public function extend(string $driver, Closure $factory): void
    {
        $this->customCreators[$driver] = $factory;
    }

    /**
     * The default disk name from config('filesystem.default'), falling back
     * to the legacy IonDisk key config('filesystem.disks.default') — a string
     * hosts traditionally feed from the FILESYSTEM_DISK env — then 'local'.
     */
    public function getDefaultDriver(): string
    {
        $default = config('filesystem.default');
        if (is_string($default) && $default !== '') {
            return $default;
        }

        $legacy = config('filesystem.disks.default');
        if (is_string($legacy) && $legacy !== '') {
            return $legacy;
        }

        return 'local';
    }

    /**
     * The list of supported driver names (built-in + custom).
     *
     * @return list<string>
     */
    public function getDrivers(): array
    {
        return array_values(array_unique(array_merge(
            ['local', 'memory', 's3', 'ftp', 'sftp'],
            array_keys($this->customCreators)
        )));
    }

    /**
     * Replace a named disk with the given Filesystem instance, bypassing its
     * configuration. Subsequent disk($name) calls return this instance until
     * it is forgotten. Used by {@see Storage::fake()} to force a fresh
     * in-memory disk in tests, whatever driver the disk is configured with.
     */
    public function set(string $name, Filesystem $filesystem): void
    {
        $this->disks[$name] = $filesystem;
        $this->overridden[$name] = true;
    }

    /**
     * Whether the named disk was injected via {@see set()} (e.g. swapped by
     * Storage::fake()) instead of being resolved from configuration. Legacy
     * surfaces (IonDisk/IonUpload) consult this to route their otherwise
     * native local-file operations through the injected disk.
     */
    public function isOverridden(string $name): bool
    {
        return isset($this->overridden[$name]);
    }

    /**
     * Forget a cached disk (or all of them) so it is re-resolved on next access.
     */
    public function forgetDisk(?string $name = null): void
    {
        if ($name === null) {
            $this->disks = [];
            $this->overridden = [];
            return;
        }

        unset($this->disks[$name], $this->overridden[$name]);
    }

    /**
     * Build a Filesystem from an inline config array (driver + opts) without
     * caching it as a named disk. Useful for ad-hoc/runtime-configured disks.
     *
     * @param array<string, mixed> $config
     */
    public function buildDisk(array $config): Filesystem
    {
        return $this->makeFilesystem($config);
    }

    /**
     * Build a Filesystem for the named disk from its config entry.
     */
    private function resolve(string $name): Filesystem
    {
        return $this->makeFilesystem($this->configuration($name));
    }

    /**
     * Construct a Filesystem from a resolved config array.
     *
     * @param array<string, mixed> $config
     */
    private function makeFilesystem(array $config): Filesystem
    {
        $driver = (string) ($config['driver'] ?? '');

        // Laravel-style alias: a disk's 'url' key feeds Flysystem's public
        // URL generator unless an explicit 'public_url' is already set.
        if (!isset($config['public_url']) && isset($config['url'])) {
            $config['public_url'] = $config['url'];
        }

        if (isset($this->customCreators[$driver])) {
            $adapter = ($this->customCreators[$driver])($config);
        } else {
            $adapter = match ($driver) {
                'local' => $this->createLocalAdapter($config),
                'memory' => $this->createMemoryAdapter($config),
                's3' => $this->createS3Adapter($config),
                'ftp' => $this->createFtpAdapter($config),
                'sftp' => $this->createSftpAdapter($config),
                default => throw new InvalidArgumentException("Unsupported filesystem driver [{$driver}]"),
            };
        }

        // Pass the disk config through so Flysystem can resolve a public URL
        // generator (config key 'public_url') and other operation defaults.
        return new Filesystem($adapter, $config);
    }

    /**
     * Read and validate a disk's config entry.
     *
     * @return array<string, mixed>
     */
    private function configuration(string $name): array
    {
        /** @var array<string, mixed>|null $config */
        $config = config("filesystem.disks.{$name}");

        if (!is_array($config)) {
            throw new InvalidArgumentException("Filesystem disk [{$name}] is not configured.");
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createLocalAdapter(array $config): FilesystemAdapter
    {
        return new LocalFilesystemAdapter((string) ($config['root'] ?? ''));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createMemoryAdapter(array $config): FilesystemAdapter
    {
        return new InMemoryFilesystemAdapter();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createS3Adapter(array $config): FilesystemAdapter
    {
        $clientOptions = [
            'region' => $config['region'] ?? null,
            'version' => $config['version'] ?? 'latest',
        ];

        if (isset($config['endpoint'])) {
            $clientOptions['endpoint'] = $config['endpoint'];
        }
        if (isset($config['use_path_style_endpoint'])) {
            $clientOptions['use_path_style_endpoint'] = $config['use_path_style_endpoint'];
        }
        if (isset($config['key'], $config['secret'])) {
            $clientOptions['credentials'] = [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ];
        }

        return new AwsS3V3Adapter(
            new S3Client($clientOptions),
            (string) ($config['bucket'] ?? ''),
            (string) ($config['root'] ?? $config['prefix'] ?? '')
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createFtpAdapter(array $config): FilesystemAdapter
    {
        return new FtpAdapter(FtpConnectionOptions::fromArray($config));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createSftpAdapter(array $config): FilesystemAdapter
    {
        return new SftpAdapter(
            SftpConnectionProvider::fromArray($config),
            (string) ($config['root'] ?? '')
        );
    }
}
