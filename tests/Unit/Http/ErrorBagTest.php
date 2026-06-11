<?php

declare(strict_types=1);

use Ions\Http\ErrorBag;

test('an empty bag reports no errors and never throws', function () {
    $bag = new ErrorBag();

    expect($bag->any())->toBeFalse()
        ->and($bag->count())->toBe(0)
        ->and($bag->has('email'))->toBeFalse()
        ->and($bag->first('email'))->toBeNull()
        ->and($bag->first())->toBeNull()
        ->and($bag->get('email'))->toBe([])
        ->and($bag->all())->toBe([])
        ->and($bag->messages())->toBe([]);
});

test('messages are normalized to string lists per field', function () {
    $bag = new ErrorBag([
        'email' => 'The email is invalid.',
        'name' => ['The name is required.', 'The name is too short.'],
    ]);

    expect($bag->any())->toBeTrue()
        ->and($bag->count())->toBe(3)
        ->and($bag->has('email'))->toBeTrue()
        ->and($bag->has('missing'))->toBeFalse()
        ->and($bag->first('email'))->toBe('The email is invalid.')
        ->and($bag->first())->toBe('The email is invalid.')
        ->and($bag->get('name'))->toBe(['The name is required.', 'The name is too short.'])
        ->and($bag->all())->toBe([
            'The email is invalid.',
            'The name is required.',
            'The name is too short.',
        ])
        ->and($bag->messages())->toBe([
            'email' => ['The email is invalid.'],
            'name' => ['The name is required.', 'The name is too short.'],
        ]);
});

test('the bag supports ArrayAccess and Countable', function () {
    $bag = new ErrorBag(['email' => ['bad email'], 'name' => ['bad name', 'still bad']]);

    expect(isset($bag['email']))->toBeTrue()
        ->and(isset($bag['missing']))->toBeFalse()
        ->and($bag['email'])->toBe(['bad email'])
        ->and($bag['missing'])->toBe([])
        ->and(count($bag))->toBe(3);
});

test('the bag is read-only through ArrayAccess', function () {
    $bag = new ErrorBag();

    expect(fn () => $bag['email'] = ['nope'])->toThrow(LogicException::class)
        ->and(function () use ($bag) {
            unset($bag['email']);
        })->toThrow(LogicException::class);
});

test('non-stringable message values are dropped, scalar fields are kept', function () {
    $bag = new ErrorBag([
        'ok' => 42,
        'weird' => [['nested-array-is-dropped'], 'kept'],
    ]);

    expect($bag->first('ok'))->toBe('42')
        ->and($bag->get('weird'))->toBe(['kept']);
});
