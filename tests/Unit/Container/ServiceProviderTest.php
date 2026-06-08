<?php

use Ions\Container\Container;
use Ions\Container\ServiceProvider;

test('register binds into the container; boot runs after', function () {
    $c = new Container();
    $provider = new class ($c) extends ServiceProvider {
        public bool $booted = false;
        public function register(): void
        {
            $this->container->instance('thing', 'value');
        }
        public function boot(): void
        {
            $this->booted = true;
        }
    };
    $provider->register();
    expect($c->get('thing'))->toBe('value');
    expect($provider->booted)->toBeFalse();
    $provider->boot();
    expect($provider->booted)->toBeTrue();
});

test('boot has a no-op default', function () {
    $c = new Container();
    $provider = new class ($c) extends ServiceProvider {
        public function register(): void
        {
        }
    };
    $provider->boot(); // must not error
    expect(true)->toBeTrue();
});
