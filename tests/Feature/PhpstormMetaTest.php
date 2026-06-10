<?php

declare(strict_types=1);

use Ions\Foundation\Kernel;

/**
 * Guards the root .phpstorm.meta.php (IDE type inference for container ids
 * and the app() helper) against drift: the file must stay valid PHP, every
 * type it references must exist, the three override maps must stay in sync,
 * and — for the ids the fixture app can actually resolve — the container
 * must return an instance of the mapped type.
 */
const PHPSTORM_META_FILE = __DIR__ . '/../../.phpstorm.meta.php';

test('.phpstorm.meta.php exists at the repo root and passes php -l', function () {
    expect(is_file(PHPSTORM_META_FILE))->toBeTrue();

    $lint = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg(PHPSTORM_META_FILE) . ' 2>&1');

    expect($lint)->not->toBeNull()
        ->and($lint)->toContain('No syntax errors detected');
});

test('every ::class reference in the meta file resolves to a real class or interface', function () {
    $source = (string) file_get_contents(PHPSTORM_META_FILE);

    preg_match_all('/\\\\([A-Za-z_][\\w\\\\]*)::class/', $source, $matches);
    $types = array_unique($matches[1]);

    expect($types)->not->toBeEmpty();

    foreach ($types as $fqcn) {
        expect(class_exists($fqcn) || interface_exists($fqcn))
            ->toBeTrue("Meta file references {$fqcn}, which does not exist");
    }
});

test('the get(), make() and app() override maps are identical', function () {
    $source = (string) file_get_contents(PHPSTORM_META_FILE);

    preg_match_all('/map\(\[(.*?)\]\)/s', $source, $matches);
    $maps = array_map(
        // Normalize whitespace so formatting differences don't count as drift.
        static fn (string $body): string => preg_replace('/\s+/', '', $body),
        $matches[1],
    );

    expect($maps)->toHaveCount(3)
        ->and($maps[1])->toBe($maps[0])
        ->and($maps[2])->toBe($maps[0]);
});

test('the meta map matches what the container actually resolves', function () {
    bootFixtureKernel();

    // Ids resolvable from the fixture app. Intentionally skipped:
    //  - 'mailer'           lazy singleton whose factory builds an SMTP DSN from
    //                       MAIL_* env vars not present in the fixture.
    //  - 'jwt'              resolves to null without a >=32-char APP_KEY.
    //  - 'revocation_store' writes through the persistent file cache store;
    //                       covered by AuthProviderTest, no need to touch disk here.
    //  - 'queue.connection' / 'cache.store' / 'db.*' / 'filesystem.disk' —
    //                       derived bindings of managers already asserted below.
    $expected = [
        'cache' => \Illuminate\Cache\CacheManager::class,
        'config' => \Ions\Foundation\Config::class,
        'csrf' => \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class,
        'events' => \Illuminate\Events\Dispatcher::class,
        'files' => \Illuminate\Filesystem\Filesystem::class,
        'filesystem' => \Illuminate\Filesystem\Filesystem::class,
        'filesystem.manager' => \Ions\Filesystem\FilesystemManager::class,
        'http' => \Symfony\Contracts\HttpClient\HttpClientInterface::class,
        'queue' => \Illuminate\Queue\QueueManager::class,
        'request_stack' => \Symfony\Component\HttpFoundation\RequestStack::class,
        'session' => \Ions\Session\SessionManager::class,
        'user_provider' => \Ions\Auth\Contracts\UserProvider::class,
        'view' => \Ions\View\ViewFactory::class,
    ];

    $source = (string) file_get_contents(PHPSTORM_META_FILE);

    foreach ($expected as $id => $type) {
        // The meta file must declare exactly this id => type pair...
        expect($source)->toContain("'{$id}' => \\{$type}::class");

        // ...and the live container must agree with it.
        expect(Kernel::app()->get($id))->toBeInstanceOf($type, "Container id '{$id}'");
    }
});

test('app() with a class-string argument resolves that class (the "" => "@" convention)', function () {
    bootFixtureKernel();

    // The meta maps '' => '@' so app(SomeClass::class) infers SomeClass; assert
    // the runtime behaviour the convention describes.
    $source = (string) file_get_contents(PHPSTORM_META_FILE);
    expect($source)->toContain("'' => '@'");

    expect(app())->toBe(Kernel::app())
        ->and(app(\Ions\Filesystem\FilesystemManager::class))
        ->toBeInstanceOf(\Ions\Filesystem\FilesystemManager::class);
});
