<?php

/**
 * Generator stub tests — Task 6.2 (Phase 6).
 *
 * Assert that the new make:middleware and make:service-provider generators
 * emit correct modern stubs, and that the controller stub no longer uses
 * echo/exit (returns a Response instead).
 */

test('middleware stub implements MiddlewareInterface with a handle method', function () {
    $stub = file_get_contents(__DIR__ . '/../../../src/commands/stubs/middleware.stub');
    expect($stub)->toContain('implements MiddlewareInterface')
        ->and($stub)->toContain('public function handle(Request $request, callable $next): Response');
});

test('service provider stub extends ServiceProvider with register + boot', function () {
    $stub = file_get_contents(__DIR__ . '/../../../src/commands/stubs/service_provider.stub');
    expect($stub)->toContain('extends ServiceProvider')
        ->and($stub)->toContain('public function register(): void')
        ->and($stub)->toContain('public function boot(): void');
});

test('the controller stub does not use echo/exit (returns a Response)', function () {
    $stub = file_get_contents(__DIR__ . '/../../../src/commands/controller/controller.stub');
    expect($stub)->not->toContain('exit(')
        ->and($stub)->not->toMatch('/\becho\b/');
});

test('MakeMiddlewareCommand class exists and has correct signature', function () {
    $src = file_get_contents(__DIR__ . '/../../../src/commands/MakeMiddlewareCommand.php');
    expect($src)->toContain("'make:middleware {name}'")
        ->and($src)->toContain('class MakeMiddlewareCommand');
});

test('MakeServiceProviderCommand class exists and has correct signature', function () {
    $src = file_get_contents(__DIR__ . '/../../../src/commands/MakeServiceProviderCommand.php');
    expect($src)->toContain("'make:service-provider {name}'")
        ->and($src)->toContain('class MakeServiceProviderCommand');
});
