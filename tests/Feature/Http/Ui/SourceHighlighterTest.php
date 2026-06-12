<?php

declare(strict_types=1);

use Ions\Http\Ui\SourceHighlighter;

test('highlight() wraps keywords, strings and variables in tok-* spans', function () {
    $html = SourceHighlighter::highlight('<?php function foo(){ $x = "bar"; return $x; }');

    expect($html)
        ->toContain('class="tok-keyword"')
        ->toContain('class="tok-string"')
        ->toContain('class="tok-variable"');
    // The keyword text is present.
    expect($html)->toContain('function')->toContain('return');
});

test('highlight() HTML-escapes string token text so source cannot inject markup', function () {
    $html = SourceHighlighter::highlight('<?php $x = "</span><script>alert(1)</script>";');

    // No live script tag may survive into the output.
    expect($html)->not->toContain('<script>');
    // The payload is escaped instead.
    expect($html)->toContain('&lt;script&gt;');
});

test('highlight() degrades to escaped text on malformed php without throwing', function () {
    $html = SourceHighlighter::highlight('<?php function foo( { "unterminated');

    expect($html)->toBeString()->not->toContain('<script>');
});

test('excerpt() returns empty string for an unreadable file', function () {
    expect(SourceHighlighter::excerpt('/no/such/file/at/all.php', 3))->toBe('');
});

test('excerpt() highlights the error line and renders a code block', function () {
    $file = tempnam(sys_get_temp_dir(), 'ion_hl_') . '.php';
    file_put_contents($file, "<?php\n\$a = 1;\n\$b = 2;\nthrow new Exception('boom');\n\$c = 3;\n");

    $html = SourceHighlighter::excerpt($file, 4, 2);

    expect($html)
        ->toContain('class="line-err"')
        ->toContain('ion-code')
        ->toContain('boom');

    @unlink($file);
});

test('highlight() escapes comment and number token text', function () {
    $html = SourceHighlighter::highlight("<?php /* <b>c</b> */ \$n = 42;");

    expect($html)
        ->toContain('class="tok-comment"')
        ->toContain('class="tok-number"')
        ->toContain('&lt;b&gt;')   // comment HTML escaped
        ->not->toContain('<b>')
        ->toContain('42');
});

test('a multi-line string token does not break a span across newlines', function () {
    // The string literal spans three physical lines; each line must be a
    // balanced run of spans so line numbering stays outside the token span.
    $php = "<?php\n\$s = \"line1\nline2\nline3\";\n";
    $lines = explode("\n", SourceHighlighter::highlight($php));

    foreach ($lines as $line) {
        // Every <span> opened on a line is closed on the SAME line.
        expect(substr_count($line, '<span'))->toBe(substr_count($line, '</span>'));
    }
});

test('excerpt() keeps the line-number gutter outside token spans for multi-line strings', function () {
    $file = tempnam(sys_get_temp_dir(), 'ion_hl_') . '.php';
    file_put_contents($file, "<?php\n\$s = \"alpha\nbeta\ngamma\";\nthrow new Exception('x');\n");

    $html = SourceHighlighter::excerpt($file, 4, 3);

    // Balanced spans across the whole excerpt (no span left open by a split).
    expect(substr_count($html, '<span'))->toBe(substr_count($html, '</span>'))
        ->and($html)->toContain('class="ln"')
        ->toContain('class="line-err"');

    @unlink($file);
});

test('excerpt() escapes XSS payloads in the source file', function () {
    $file = tempnam(sys_get_temp_dir(), 'ion_hl_') . '.php';
    file_put_contents($file, "<?php\n\$x = \"<script>alert(1)</script>\";\n");

    $html = SourceHighlighter::excerpt($file, 2, 2);

    expect($html)->not->toContain('<script>');
    expect($html)->toContain('&lt;script&gt;');

    @unlink($file);
});
