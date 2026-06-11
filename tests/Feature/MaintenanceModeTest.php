<?php

declare(strict_types=1);

use Ions\Bundles\Path;
use Ions\commands\DownCommand;
use Ions\commands\UpCommand;
use Ions\Foundation\Kernel;
use Ions\Foundation\MaintenanceMode;
use Ions\Support\Request;

/*
|--------------------------------------------------------------------------
| Maintenance mode (Phase 10.8)
|--------------------------------------------------------------------------
| `ions down` writes var/maintenance.php; Kernel::handle() checks it early
| (after trusted proxies, before routing) and throws HttpException(503)
| through the ExceptionHandler — so errors/503.twig theming and the API JSON
| shape both apply. `--retry` adds a Retry-After header; `--secret` enables
| the Laravel-style bypass: visiting /{secret} sets the ions_maintenance
| cookie and redirects to '/', after which requests pass. The /up liveness
| probe stays reachable so ops can monitor the box DURING maintenance.
| `ions up` deletes the flag file and restores normal serving.
*/

beforeEach(function () {
    bootFixtureKernel();
});

afterEach(function () {
    if (file_exists(Path::var('maintenance.php'))) {
        unlink(Path::var('maintenance.php'));
    }
    putenv('APP_DEBUG');
    unset($_ENV['APP_DEBUG']);
});

test('ions down puts the app into maintenance: web requests get a 503', function () {
    $tester = runConsoleCommand(new DownCommand());

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('maintenance')
        ->and(file_exists(Path::var('maintenance.php')))->toBeTrue();

    $response = Kernel::handle(Request::create('/ping'));
    expect($response->getStatusCode())->toBe(503);
});

test('--retry adds a Retry-After header to the 503', function () {
    runConsoleCommand(new DownCommand(), ['--retry' => '120']);

    $response = Kernel::handle(Request::create('/ping'));
    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Retry-After'))->toBe('120');
});

test('the 503 is themeable: errors/503.twig renders through the handler chain', function () {
    config(['app.twig.source' => Path::root('views')]);
    runConsoleCommand(new DownCommand());

    $response = Kernel::handle(Request::create('/ping'));
    expect($response->getStatusCode())->toBe(503)
        ->and((string) $response->getContent())->toContain('CUSTOM-503')
        ->and((string) $response->getContent())->toContain('status=503');
});

test('api requests get the standard 503 JSON error shape', function () {
    runConsoleCommand(new DownCommand(), ['--retry' => '60']);

    $response = Kernel::handle(Request::create('/api/anything'));
    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Retry-After'))->toBe('60')
        ->and(json_decode((string) $response->getContent(), true))->toBe([
            'status' => 'error',
            'message' => 'Service Unavailable',
            'code' => 503,
        ]);
});

test('visiting /{secret} sets the bypass cookie and redirects home', function () {
    runConsoleCommand(new DownCommand(), ['--secret' => 'letmein']);

    $response = Kernel::handle(Request::create('/letmein'));

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe('/');

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === MaintenanceMode::COOKIE);
    expect($cookie)->not->toBeNull()
        ->and($cookie->getValue())->toBe(hash('sha256', 'letmein'));
});

test('a request carrying a valid bypass cookie passes through to the app', function () {
    runConsoleCommand(new DownCommand(), ['--secret' => 'letmein']);

    $request = Request::create('/ping');
    $request->cookies->set(MaintenanceMode::COOKIE, hash('sha256', 'letmein'));

    $response = Kernel::handle($request);
    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getContent())->toBe('pong');
});

test('a wrong bypass cookie still gets the 503', function () {
    runConsoleCommand(new DownCommand(), ['--secret' => 'letmein']);

    $request = Request::create('/ping');
    $request->cookies->set(MaintenanceMode::COOKIE, hash('sha256', 'wrong-guess'));

    expect(Kernel::handle($request)->getStatusCode())->toBe(503);
});

test('the /up liveness probe stays reachable during maintenance', function () {
    runConsoleCommand(new DownCommand());

    $response = Kernel::handle(Request::create('/up'));
    expect($response->getStatusCode())->toBe(200);
});

test('ions up removes the flag file and restores normal serving', function () {
    runConsoleCommand(new DownCommand());
    expect(Kernel::handle(Request::create('/ping'))->getStatusCode())->toBe(503);

    $tester = runConsoleCommand(new UpCommand());
    expect($tester->getStatusCode())->toBe(0)
        ->and(file_exists(Path::var('maintenance.php')))->toBeFalse();

    $response = Kernel::handle(Request::create('/ping'));
    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getContent())->toBe('pong');
});

test('ions up when not in maintenance is a friendly no-op', function () {
    $tester = runConsoleCommand(new UpCommand());
    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('not in maintenance');
});

test('doctor reports a WARN row while maintenance mode is active', function () {
    runConsoleCommand(new DownCommand());

    $tester = runConsoleCommand(new \Ions\commands\DoctorCommand(), ['--json' => true]);
    $payload = json_decode($tester->getDisplay(), true);
    $row = collect($payload['checks'])->firstWhere('id', 'maintenance');

    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe('warn');
});

test('doctor reports maintenance OK when the app is live', function () {
    $tester = runConsoleCommand(new \Ions\commands\DoctorCommand(), ['--json' => true]);
    $payload = json_decode($tester->getDisplay(), true);
    $row = collect($payload['checks'])->firstWhere('id', 'maintenance');

    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe('ok');
});
