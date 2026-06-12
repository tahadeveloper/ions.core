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

test('no-override make() calls reuse ONE shared Twig Environment per process', function () {
    /** @var ViewFactory $factory */
    $factory = Kernel::app()->get('view');

    $first = $factory->make();
    $second = $factory->make();

    expect(spl_object_id($second))->toBe(spl_object_id($first))
        ->and(Kernel::app()->bound('view.env'))->toBeTrue()
        ->and(spl_object_id(Kernel::app()->get('view.env')))->toBe(spl_object_id($first));
});

test('TwigInit() (controller trait) reuses the shared Environment', function () {
    $a = new class () {
        use \Ions\Traits\Twig;
    };
    $b = new class () {
        use \Ions\Traits\Twig;
    };

    $a->TwigInit();
    $b->TwigInit();

    expect(spl_object_id($b->twig))->toBe(spl_object_id($a->twig));
});

test('make() with explicit overrides still builds a fresh Environment', function () {
    /** @var ViewFactory $factory */
    $factory = Kernel::app()->get('view');

    $shared = $factory->make();
    $custom = $factory->make(sys_get_temp_dir());

    expect(spl_object_id($custom))->not->toBe(spl_object_id($shared));
});

test('re-booting the kernel yields a fresh shared Environment (no stale container)', function () {
    $first = Kernel::app()->get('view')->make();
    bootFixtureKernel();
    $second = Kernel::app()->get('view')->make();

    expect(spl_object_id($second))->not->toBe(spl_object_id($first));
});

test("tJson is pre-registered at build so a render-initialized env can still set it (worker-mode safe)", function () {
    /** @var ViewFactory $factory */
    $factory = Kernel::app()->get('view');
    $env = $factory->make(sys_get_temp_dir());

    // tJson is a registered global from build (even when empty) — this is what
    // makes the later addGlobal('tJson', …) calls in BaseController/render() a
    // safe UPDATE rather than an ADD-after-init.
    expect(array_key_exists('tJson', $env->getGlobals()))->toBeTrue();

    // Initialize the env the way a Mailable render does (a render loads the
    // extension set / runtime). After that, adding a NEW global would throw;
    // updating tJson must NOT — mirroring the next web request's controller.
    file_put_contents(sys_get_temp_dir() . '/wm_probe.twig', 'hello {{ tJson }}');
    $env->render('wm_probe.twig');

    // Before the fix this threw: LogicException "Unable to add global tJson …".
    $env->addGlobal('tJson', '{"k":"v"}');
    expect($env->getGlobals()['tJson'])->toBe('{"k":"v"}');

    @unlink(sys_get_temp_dir() . '/wm_probe.twig');
});
