<?php

declare(strict_types=1);

use Ions\Session\SessionManager;

test('put/get/has/forget over the array driver', function () {
    $s = new SessionManager(['driver' => 'array']);
    $s->start();
    $s->put('k', 'v');
    expect($s->get('k'))->toBe('v')->and($s->has('k'))->toBeTrue();
    $s->forget('k');
    expect($s->has('k'))->toBeFalse();
});

test('get returns the supplied default when the key is absent', function () {
    $s = new SessionManager(['driver' => 'array']);
    $s->start();
    expect($s->get('missing', 'fallback'))->toBe('fallback');
});

test('all returns every stored attribute', function () {
    $s = new SessionManager(['driver' => 'array']);
    $s->start();
    $s->put('a', 1);
    $s->put('b', 2);
    expect($s->all())->toBe(['a' => 1, 'b' => 2]);
});

test('flush clears all stored attributes', function () {
    $s = new SessionManager(['driver' => 'array']);
    $s->start();
    $s->put('a', 1);
    $s->flush();
    expect($s->all())->toBe([])->and($s->has('a'))->toBeFalse();
});

test('flash data is retrievable', function () {
    $s = new SessionManager(['driver' => 'array']);
    $s->start();
    $s->flash('notice', 'saved');
    expect($s->getFlash('notice'))->toBe(['saved']);
});

test('regenerate changes the id but keeps data', function () {
    $s = new SessionManager(['driver' => 'array']);
    $s->start();
    $s->put('keep', 'me');
    $id1 = $s->getId();
    expect($s->regenerate())->toBeTrue();
    expect($s->getId())->not->toBe($id1)
        ->and($s->get('keep'))->toBe('me');
});

test('token returns a non-empty string and is stable within a session', function () {
    $s = new SessionManager(['driver' => 'array']);
    $s->start();
    $token = $s->token();
    expect($token)->toBeString()->not->toBe('')
        ->and($s->token())->toBe($token);
});

test('mock is an alias for the array driver', function () {
    $s = new SessionManager(['driver' => 'mock']);
    $s->start();
    $s->put('k', 'v');
    expect($s->get('k'))->toBe('v');
});

test('exposes the underlying symfony session', function () {
    $s = new SessionManager(['driver' => 'array']);
    $s->start();
    expect($s->getSession())->toBeInstanceOf(\Symfony\Component\HttpFoundation\Session\SessionInterface::class);
});
