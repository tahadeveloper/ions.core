# Media — `Ions\Media\Image`

Image processing (resize / crop / cover / watermark / encode) restored on top of
[intervention/image](https://image.intervention.io/) v3. This brings back the
capability that was dropped when `verot/class.upload.php` was removed in 3.0.0,
behind a thin, fluent wrapper that delegates to Intervention and converts every
failure into a single framework exception, `Ions\Media\ImageException`.

## Driver

Intervention v3 runs on a driver — **GD** (ships with most PHP builds) or
**Imagick**. The driver is read from config:

```php
// config/media.php
return [
    'driver' => 'gd', // or 'imagick'
];
```

`config('media.driver', 'gd')` defaults to GD. When the class is used outside a
booted host application (e.g. in isolation), it falls back to GD automatically.

## Constructing

```php
use Ions\Media\Image;

$img = Image::read($path);          // load from a file path
$img = Image::make($path);          // alias of read()
$img = Image::fromString($binary);  // decode raw bytes (e.g. an upload stream)
```

A missing file, an unreadable path, or undecodable bytes throw
`Ions\Media\ImageException`.

## Fluent operations

Each returns `$this`, so calls chain:

| Method | Intervention v3 call | Notes |
| --- | --- | --- |
| `resize(int $w, int $h)` | `resize()` | Exact size; does not preserve aspect ratio. |
| `scale(?int $w = null, ?int $h = null)` | `scale()` | Aspect-preserving; pass either side. |
| `crop(int $w, int $h, int $x = 0, int $y = 0)` | `crop()` | Sub-region from offset `($x, $y)`. |
| `cover(int $w, int $h)` | `cover()` | Resize + crop to fill the box (no distortion). |
| `watermark(string $path, string $position = 'bottom-right', int $opacity = 100)` | `place()` | Overlay another image. |
| `quality(int $q)` / `encodeBy(int $q)` | encoder option | Sets quality for the next encode. |

`$position` accepts Intervention's anchors: `top-left`, `top`, `top-right`,
`left`, `center`, `right`, `bottom-left`, `bottom`, `bottom-right`.

## Output

```php
$img->width();                          // int
$img->height();                         // int
$img->save($path, $quality = null);     // encode by the path extension -> bool
$img->toString('png', $quality = 90);   // encode to a binary string
$img->intervention();                   // the underlying Intervention image (escape hatch)
```

`save()` encodes by the target path's extension (`jpg`, `png`, `webp`, `gif`, …)
and returns `true` on success. An unsupported extension throws
`ImageException`. Map to Intervention v3 internally via `encodeByExtension()` +
`EncodedImage::save()` / `toString()`.

### Examples

```php
// Thumbnail that fills a 300x300 box at 80% quality
Image::read($src)->cover(300, 300)->quality(80)->save($thumb);

// Watermark, then re-encode to webp bytes
$webp = Image::read($src)
    ->watermark($logo, 'bottom-right', 60)
    ->toString('webp', 85);
```

## Upload integration (optional)

`Ions\Bundles\IonUpload::store()` accepts an optional `image` callback. When
present, the stored file is post-processed after the move. Non-image uploads
never touch the media stack — the hook only runs when you opt in:

```php
use Ions\Bundles\IonUpload;
use Ions\Media\Image;

$result = IonUpload::store($request->file('photo'), $dest, [
    'image' => fn (Image $img, string $stored) => $img->cover(300, 300)->save($stored),
])->response();
```

The callback receives the loaded `Ions\Media\Image` plus the absolute stored
path, so it can resize/crop/watermark and `save()` back in place. Any
`ImageException` is captured into the upload result's `error`/`message`.

Prefer not to couple processing to the upload? Do it explicitly anywhere:

```php
Image::read($storedPath)->cover(300, 300)->save($storedPath);
```
