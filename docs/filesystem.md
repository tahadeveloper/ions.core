# Filesystem

The Ions filesystem is a config-driven, multi-driver abstraction over
[Flysystem](https://flysystem.thephpleague.com/) v3. Apps declare named *disks*
in `config/filesystem.php` and swap between `local`, `s3`, `ftp`, `sftp`, and
`memory` storage without touching call sites.

## Components

| Class | Role |
| ----- | ---- |
| `Ions\Filesystem\FilesystemManager` | Resolves named disks from `filesystem.disks`, caches them per name, supports custom drivers via `extend()`. Bound in the container as `filesystem.manager`. |
| `Ions\Filesystem\Storage` | Thin static facade over the bound manager — the ergonomic entry point. |
| `Ions\Bundles\IonDisk` | Legacy static disk API; resolves its disks through the manager (kept for BC, removal candidate for 5.0 — prefer `Storage`). |

## Configuration

See the full reference in [config.md](config.md#filesystem-config-configfilesystemphp).
In short:

```php
// config/filesystem.php
return [
    'default' => 'local',
    'disks' => [
        'local'  => ['driver' => 'local', 'root' => Path::filesRoot()],
        'memory' => ['driver' => 'memory'],
        's3'     => ['driver' => 's3', 'key' => env('AWS_ACCESS_KEY_ID'), /* ... */],
        'ftp'    => ['driver' => 'ftp', 'host' => env('FTP_HOST'), /* ... */],
        'sftp'   => ['driver' => 'sftp', 'host' => env('SFTP_HOST'), /* ... */],
    ],
];
```

`filesystem.default` is the disk returned when no name is given. Each disk entry
MUST declare a `driver`; an unknown driver throws
`InvalidArgumentException("Unsupported filesystem driver [...]")`.

## Using `Storage`

`Ions\Filesystem\Storage` is a static facade; every method delegates to the
container-bound manager and operates on the **default disk** (use
`Storage::disk($name)` for a specific one — it returns a raw Flysystem
`Filesystem`).

```php
use Ions\Filesystem\Storage;

Storage::put('reports/jan.csv', $contents);   // write (default disk)
$body  = Storage::get('reports/jan.csv');     // read
$ok    = Storage::exists('reports/jan.csv');  // bool
Storage::delete('reports/jan.csv');           // remove
$url   = Storage::url('reports/jan.csv');     // public URL (see URL semantics below)

// A specific disk — returns a Flysystem Filesystem
Storage::disk('s3')->write('avatars/1.png', $png);
Storage::disk('memory')->read('tmp.txt');

Storage::manager();   // the FilesystemManager itself
```

### Method reference

| Method | Does |
| ------ | ---- |
| `put($path, $contents, $config = [])` | Write string contents |
| `get($path)` / `exists($path)` / `delete($path)` | Read / check / remove |
| `putFile($dir, $file)` | Store an `UploadedFile`, `SplFileInfo`, or local file path under `$dir` with a random name (extension preserved); returns the stored path. No upload validation — user uploads go through `IonUpload`/`UploadValidator`. |
| `download($path, $name = null, $headers = [])` | Symfony `StreamedResponse` attachment (`Content-Disposition`, MIME from the disk; `$name` overrides the download filename) |
| `files($dir = '', $recursive = false)` | Sorted list of file paths |
| `directories($dir = '', $recursive = false)` | Sorted list of directory paths |
| `copy($from, $to)` / `move($from, $to)` | Copy (source kept) / move (source removed) |
| `url($path)` | Public URL — see below |
| `temporaryUrl($path, $expiresAt)` | Signed expiring URL (s3); `$expiresAt` is a `DateTimeInterface` or TTL seconds |
| `disk($name = null)` / `manager()` | Raw Flysystem disk / the manager |
| `fake($disk = null)` | Swap a disk for an in-memory fake — see [testing.md](testing.md) |

### URL semantics — `url()` / `temporaryUrl()`

`Storage::url($path)` asks the disk's Flysystem public-URL generator:

1. a disk with a `public_url` config key (or its Laravel-style alias `url`)
   prefixes the path with it;
2. `s3` disks generate the canonical object URL via the AWS adapter;
3. disks with neither fall back to `config('app.app_url') . '/' . $path`.

`Storage::temporaryUrl($path, $expiresAt)` produces a signed, expiring URL.
Only drivers whose adapter implements Flysystem's `TemporaryUrlGenerator`
support it — `s3` does; `local`/`memory`/`ftp`/`sftp` throw a
`RuntimeException` (`"... does not support temporary URLs"`).

## The manager directly

```php
$fs = app('filesystem.manager');             // FilesystemManager
$disk = $fs->disk();                          // default disk (Flysystem Filesystem)
$disk = $fs->disk('ftp');                     // a named disk
$fs->getDefaultDriver();                      // 'local'
$fs->getDrivers();                            // resolved disk instances, keyed by name
$fs->forgetDisk('s3');                        // drop a cached instance
```

### Custom drivers — `extend()`

Register an extra driver factory before the disk is first resolved:

```php
app('filesystem.manager')->extend('mydriver', function (array $config): \League\Flysystem\Filesystem {
    return new \League\Flysystem\Filesystem(new MyAdapter($config));
});
```

Then reference `'driver' => 'mydriver'` from a disk entry.

## Uploads & `IonDisk`

`Ions\Bundles\IonDisk` keeps its existing static API but resolves its disks
through the shared manager (it reads the legacy `filesystem.disks.default`
string to pick its disk type — hosts traditionally feed that key from the
`FILESYSTEM_DISK` env). Its `local` type maps onto the manager's named
`local` disk; the `s3` type keeps IonDisk's runtime-mutable bucket/base-path
semantics via ad-hoc built disks. Prefer `Ions\Filesystem\Storage` in new
code — IonDisk is a removal candidate for 5.0.

Because the disks come from the manager, `Storage::fake()` in tests
intercepts IonDisk and IonUpload reads/writes too (see
[testing.md](testing.md)). Uploads still pass through
`Ions\Security\UploadValidator` (extension allow-list + magic-bytes content
check + hard-coded executable deny-list — see
[config.md](config.md#appuploadsallowed)), so the RCE-vector protection added
in 3.0 remains in force — also under a fake. `IonUpload` also runs an
optional image hook (see [media.md](media.md)); the hook operates on the real
stored file and is skipped when writes are intercepted by a fake.
