<?php

use Ions\Foundation\Kernel;
use Ions\View\ViewFactory;
use Twig\Environment;

beforeEach(fn () => bootFixtureKernel());

test('the container resolves a view factory', function () {
    expect(Kernel::app()->get('view'))->toBeInstanceOf(ViewFactory::class);
});

test('the factory builds a configured Twig environment with the core functions', function () {
    /** @var ViewFactory $factory */
    $factory = Kernel::app()->get('view');
    $env = $factory->make(sys_get_temp_dir());
    expect($env)->toBeInstanceOf(Environment::class)
        ->and($env->getFunction('trans'))->not->toBeFalse()
        ->and($env->getFunction('assets'))->not->toBeFalse();
});
