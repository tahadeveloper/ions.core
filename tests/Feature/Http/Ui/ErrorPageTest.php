<?php

declare(strict_types=1);

use Ions\Http\Ui\ErrorPage;

test('render() produces a self-contained JS-free document with status, title, message and a home link', function () {
    $html = ErrorPage::render(404, 'Not Found');

    expect($html)
        ->toContain('<!DOCTYPE html>')
        ->toContain('404')
        ->toContain('Not Found')                 // the human title AND the message
        ->toContain('href="/"')                  // back-to-home link
        ->toContain('<style>')                   // inline CSS
        ->toContain('--ion-accent');             // drawn from the DesignSystem tokens

    // JS-free, no external references.
    expect(stripos($html, '<script'))->toBeFalse();
    expect(stripos($html, '<link '))->toBeFalse();
    expect(stripos($html, 'src='))->toBeFalse();
});

test('render() maps each documented status to its human title', function () {
    $map = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        419 => 'Page Expired',
        429 => 'Too Many Requests',
        500 => 'Server Error',
        503 => 'Service Unavailable',
    ];

    foreach ($map as $status => $title) {
        $html = ErrorPage::render($status, $title);
        expect($html)->toContain((string) $status)->toContain($title);
    }
});

test('render() falls back to a generic "Error" title for an unknown status and never throws', function () {
    $html = ErrorPage::render(418, 'I am a teapot');

    expect($html)->toContain('418')->toContain('Error')->toContain('I am a teapot');
});

test('render() never throws on an empty message and still renders the page', function () {
    $html = ErrorPage::render(404, '');

    // Empty message degrades to the title so the page is never blank.
    expect($html)->toContain('404')->toContain('Not Found')->toContain('<!DOCTYPE html>');
});

test('render() HTML-escapes the message (no markup injection)', function () {
    $html = ErrorPage::render(400, '<b>x</b>"&');

    expect($html)
        ->toContain('&lt;b&gt;x&lt;/b&gt;')
        ->not->toContain('<b>x</b>');
});

test('render() never throws on an unusual status code', function () {
    expect(ErrorPage::render(0, ''))->toBeString()->toContain('<!DOCTYPE html>');
    expect(ErrorPage::render(999, 'weird'))->toBeString()->toContain('999');
});
