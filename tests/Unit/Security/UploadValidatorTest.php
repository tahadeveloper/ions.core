<?php

use Ions\Security\UploadValidator;

test('rejects php and other executable extensions', function () {
    $v = new UploadValidator(['jpg', 'png', 'pdf']);
    expect($v->isAllowed('shell.php'))->toBeFalse()
        ->and($v->isAllowed('a.phtml'))->toBeFalse()
        ->and($v->isAllowed('a.PHP5'))->toBeFalse()
        ->and($v->isAllowed('archive.phar'))->toBeFalse();
});

test('accepts allow-listed extensions case-insensitively', function () {
    $v = new UploadValidator(['jpg', 'png']);
    expect($v->isAllowed('photo.JPG'))->toBeTrue()
        ->and($v->isAllowed('image.png'))->toBeTrue();
});

test('rejects extensions not on the allow-list even if not dangerous', function () {
    $v = new UploadValidator(['jpg']);
    expect($v->isAllowed('doc.txt'))->toBeFalse();
});

test('rejects files with no extension', function () {
    $v = new UploadValidator(['jpg']);
    expect($v->isAllowed('noext'))->toBeFalse();
});

test('safeExtension strips path and lowercases', function () {
    $v = new UploadValidator(['jpg']);
    expect($v->safeExtension('../../x.JPG'))->toBe('jpg');
});

// ── Magic-bytes / MIME content validation (4.1) ─────────────────────────────

const TINY_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function tmpFileWith(string $content): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'uvt');
    file_put_contents($path, $content);
    return $path;
}

test('a real PNG passes content validation as .png', function () {
    $v = new UploadValidator(['png']);
    $path = tmpFileWith((string) base64_decode(TINY_PNG_B64));
    expect($v->isContentValid($path, 'image.png'))->toBeTrue();
    @unlink($path);
});

test('PHP source bytes named .jpg are rejected by content validation', function () {
    $v = new UploadValidator(['jpg']);
    $path = tmpFileWith('<?php system($_GET["c"]);');
    expect($v->isContentValid($path, 'innocent.jpg'))->toBeFalse();
    @unlink($path);
});

test('buffer variant validates raw content without a file on disk', function () {
    $v = new UploadValidator(['png', 'jpg']);
    expect($v->isContentValidBuffer((string) base64_decode(TINY_PNG_B64), 'a.png'))->toBeTrue()
        ->and($v->isContentValidBuffer('<?php echo 1;', 'a.jpg'))->toBeFalse();
});

test('text content agrees with txt and csv via the text/* wildcard', function () {
    $v = new UploadValidator(['txt', 'csv']);
    expect($v->isContentValidBuffer("plain text content\n", 'notes.txt'))->toBeTrue()
        ->and($v->isContentValidBuffer("a,b,c\n1,2,3\n", 'data.csv'))->toBeTrue();
});

test('an extension absent from the mime map is accepted on the extension gate alone', function () {
    // 'xyz' is not in the default map: content validation must not brick it.
    $v = new UploadValidator(['xyz']);
    expect($v->isContentValidBuffer('arbitrary bytes', 'file.xyz'))->toBeTrue();
});

test('a custom mime map overrides the default per extension', function () {
    // Force .png to only accept text/plain: real PNG bytes now FAIL, text passes.
    $v = new UploadValidator(['png'], ['png' => ['text/plain']]);
    expect($v->isContentValidBuffer((string) base64_decode(TINY_PNG_B64), 'a.png'))->toBeFalse()
        ->and($v->isContentValidBuffer('just text', 'a.png'))->toBeTrue();
});

test('isContentValid rejects a missing file path defensively', function () {
    $v = new UploadValidator(['png']);
    expect($v->isContentValid('/nonexistent/file.png', 'a.png'))->toBeFalse();
});

test('rejects newly-denied extensions phtm and inc', function () {
    $v = new UploadValidator(['jpg', 'phtm', 'inc']); // even if someone allow-lists them, DENY wins
    expect($v->isAllowed('evil.phtm'))->toBeFalse()
        ->and($v->isAllowed('config.inc'))->toBeFalse()
        ->and($v->isAllowed('shell.php9'))->toBeFalse()
        ->and($v->isAllowed('shell.php10'))->toBeFalse();
});
