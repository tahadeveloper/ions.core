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
| `Ions\Bundles\IonDisk` | Legacy static disk API; now delegates to the manager (kept for BC). |

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
container-bound manager.

```php
use Ions\Filesystem\Storage;

Storage::put('reports/jan.csv', $contents);   // write (default disk)
$body  = Storage::get('reports/jan.csv');     // read
$ok    = Storage::exists('reports/jan.csv');  // bool
Storage::delete('reports/jan.csv');           // remove
$url   = Storage::url('reports/jan.csv');      // public URL (disks with public_url / s3)

// A specific disk — returns a Flysystem Filesystem
Storage::disk('s3')->write('avatars/1.png', $png);
Storage::disk('memory')->read('tmp.txt');

Storage::manager();   // the FilesystemManager itself
```

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

`Ions\Bundles\IonDisk` keeps its existing static API but now delegates to the
manager (it reads `filesystem.disks.default` to pick the adapter). Uploads still
pass through `Ions\Security\UploadValidator` (extension allow-list +
hard-coded executable deny-list — see [config.md](config.md#appuploadsallowed)),
so the RCE-vector protection added in 3.0 remains in force. `IonUpload` also runs
an optional image hook (see [media.md](media.md)).
