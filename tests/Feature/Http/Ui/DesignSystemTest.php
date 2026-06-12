<?php

declare(strict_types=1);

use Ions\Http\Ui\DesignSystem;

test('css() returns a non-empty self-contained string', function () {
    $css = DesignSystem::css();

    expect($css)->toBeString()->not->toBe('');
});

test('css() defines the documented --ion-* color and font tokens', function () {
    $css = DesignSystem::css();

    foreach ([
        '--ion-bg',
        '--ion-surface',
        '--ion-text',
        '--ion-muted',
        '--ion-accent',
        '--ion-danger',
        '--ion-vendor',
        '--ion-border',
        '--ion-code-bg',
        '--ion-font-sans',
        '--ion-font-mono',
    ] as $token) {
        expect($css)->toContain($token);
    }

    expect($css)->toContain(':root')->toContain('color-scheme: dark light');
});

test('css() ships the shared primitive and syntax classes', function () {
    $css = DesignSystem::css();

    foreach ([
        '.ion-badge',
        'pre.ion-code',
        'table.ion-kv',
        'a.ion-link',
        '.line-err',
        '.tok-keyword',
        '.tok-string',
        '.tok-comment',
        '.tok-number',
        '.tok-variable',
        '.tok-default',
    ] as $cls) {
        expect($css)->toContain($cls);
    }
});

test('css() contains no </style>-breaking sequence', function () {
    $css = DesignSystem::css();

    expect(stripos($css, '</style'))->toBeFalse();
});

test('tokensOnly() returns just the :root token block', function () {
    $tokens = DesignSystem::tokensOnly();

    expect($tokens)->toContain(':root')->toContain('--ion-accent');
    // No primitive rules — only the token block.
    expect($tokens)->not->toContain('.ion-badge');
});
