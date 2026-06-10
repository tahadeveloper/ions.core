<?php

declare(strict_types=1);

use Ions\Foundation\Config;

/*
|--------------------------------------------------------------------------
| Typed config accessors (Task 8.6a)
|--------------------------------------------------------------------------
| Config::string()/integer()/int()/boolean()/bool()/array()/float() are
| assertion-style getters mirroring Laravel 11's typed Config accessors:
| they fetch via get($key, $default) and THROW InvalidArgumentException on
| any type mismatch. No coercion ever happens ('1' is not an int, 1 is not
| a bool, an int is not a float).
*/

function typedConfig(): Config
{
    return new Config([
        'app' => [
            'name' => 'Ions',
            'workers' => 4,
            'debug' => false,
            'preloads' => ['helpers.php'],
            'ratio' => 0.5,
            'nothing' => null,
            'nested' => ['deep' => ['flag' => true]],
            'numeric_string' => '1',
            'one' => 1,
            'zero' => 0,
        ],
    ]);
}

// ---------------------------------------------------------------- happy paths

test('string() returns a string value', function () {
    expect(typedConfig()->string('app.name'))->toBe('Ions');
});

test('integer() and its int() alias return an int value', function () {
    $config = typedConfig();
    expect($config->integer('app.workers'))->toBe(4)
        ->and($config->int('app.workers'))->toBe(4);
});

test('boolean() and its bool() alias return a bool value', function () {
    $config = typedConfig();
    expect($config->boolean('app.debug'))->toBeFalse()
        ->and($config->bool('app.debug'))->toBeFalse();
});

test('array() returns an array value', function () {
    expect(typedConfig()->array('app.preloads'))->toBe(['helpers.php']);
});

test('float() returns a float value', function () {
    expect(typedConfig()->float('app.ratio'))->toBe(0.5);
});

test('typed accessors resolve nested dot keys', function () {
    expect(typedConfig()->boolean('app.nested.deep.flag'))->toBeTrue();
});

// ------------------------------------------------------------------- defaults

test('missing key with a typed default returns the default', function () {
    $config = typedConfig();
    expect($config->string('app.missing', 'fallback'))->toBe('fallback')
        ->and($config->integer('app.missing', 7))->toBe(7)
        ->and($config->int('app.missing', 7))->toBe(7)
        ->and($config->boolean('app.missing', true))->toBeTrue()
        ->and($config->bool('app.missing', true))->toBeTrue()
        ->and($config->array('app.missing', ['a']))->toBe(['a'])
        ->and($config->float('app.missing', 1.5))->toBe(1.5);
});

test('missing key without a default throws (resolved value is null)', function () {
    typedConfig()->string('app.missing');
})->throws(InvalidArgumentException::class);

test('a stored null value throws', function () {
    typedConfig()->integer('app.nothing');
})->throws(InvalidArgumentException::class);

// ------------------------------------------------------------- no coercion

test("string '1' is NOT an int", function () {
    typedConfig()->integer('app.numeric_string');
})->throws(InvalidArgumentException::class);

test('int 1 is NOT a bool', function () {
    typedConfig()->boolean('app.one');
})->throws(InvalidArgumentException::class);

test('int 0 is NOT a bool', function () {
    typedConfig()->boolean('app.zero');
})->throws(InvalidArgumentException::class);

test('int is NOT a float', function () {
    typedConfig()->float('app.workers');
})->throws(InvalidArgumentException::class);

// -------------------------------------------------------------- message shape

test('mismatch message names the key, expected type and actual type', function () {
    try {
        typedConfig()->string('app.workers');
        $this->fail('Expected InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('app.workers')
            ->toContain('string')
            ->toContain('int');
    }
});

test('mismatch on every accessor throws InvalidArgumentException', function (string $method) {
    // string() gets an int value; every other accessor gets the string value.
    typedConfig()->{$method}($method === 'string' ? 'app.workers' : 'app.name');
})->with(['string', 'integer', 'int', 'boolean', 'bool', 'array', 'float'])
    ->throws(InvalidArgumentException::class);

// ------------------------------------------------------------ booted kernel

test('config()->string() works through the booted fixture kernel', function () {
    bootFixtureKernel();
    expect(config()->string('app.name'))->toBe('IonsFixture');
});
