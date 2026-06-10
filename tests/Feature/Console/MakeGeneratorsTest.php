<?php

/**
 * Generator command tests — Phase 8.4d.
 *
 * Covers the DX generators (make:resource / make:request / make:job /
 * make:event / make:listener / make:test / make:factory): registration on the console Kernel,
 * file creation at the right host path, stub content, flag variants, the
 * existing-file refusal + --force overwrite, and a php -l syntax sanity check
 * on at least one generated file per generator.
 */

use Ions\Bundles\Path;
use Ions\Console\Kernel;
use Ions\Support\File;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Boot the console kernel against the fixture app and return the application.
 */
function makeGeneratorsApp(): \Illuminate\Console\Application
{
    return Kernel::boot(__DIR__ . '/../../fixtures/app')->getApplication();
}

/**
 * Remove everything the generator tests may have created in the fixture.
 * None of these directories exist in the pristine fixture.
 */
function cleanGeneratedArtifacts(): void
{
    $fixture = realpath(__DIR__ . '/../../fixtures/app');

    foreach (['src/Http', 'src/Jobs', 'src/Events', 'src/Listeners', 'src/Factories', 'tests'] as $dir) {
        $path = $fixture . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
        if (is_dir($path)) {
            File::deleteDirectory($path);
        }
    }

    // Files a path-traversal name could have escaped to (hostile-input tests).
    foreach ([$fixture . '/Escaped.php', dirname($fixture) . '/Escaped.php', dirname($fixture, 2) . '/Escaped.php'] as $escaped) {
        if (is_file($escaped)) {
            File::delete($escaped);
        }
    }
}

/** Assert a generated file passes php -l. */
function expectLintOk(string $file): void
{
    $lint = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1');

    // shell_exec returns null on failure / no output — surface that as its own
    // failure instead of a confusing string assertion on null.
    expect($lint)->toBeString();
    expect((string) $lint)->toContain('No syntax errors');
}

beforeEach(function () {
    cleanGeneratedArtifacts();
});

afterEach(function () {
    cleanGeneratedArtifacts();
    \Ions\Foundation\Kernel::resetForTesting();
});

// ---------------------------------------------------------------------------
// Registration
// ---------------------------------------------------------------------------

test('all seven generators are registered on the console kernel', function () {
    $app = makeGeneratorsApp();

    foreach (['make:resource', 'make:request', 'make:job', 'make:event', 'make:listener', 'make:test', 'make:factory'] as $name) {
        expect($app->has($name))->toBeTrue();
    }
});

// ---------------------------------------------------------------------------
// Hostile input — name/option validation
// ---------------------------------------------------------------------------

test('make:job rejects a class name with spaces and punctuation', function () {
    $app = makeGeneratorsApp();

    $tester = new CommandTester($app->find('make:job'));
    $tester->execute(['name' => 'Invalid Name!']);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('Invalid class name')
        ->and(File::exists(Path::src('Jobs/Invalid Name!.php')))->toBeFalse()
        ->and(is_dir(Path::src('Jobs')) ? File::files(Path::src('Jobs')) : [])->toBe([]);
});

test('make:event rejects a path-traversal name and writes nothing outside the host', function () {
    $app = makeGeneratorsApp();
    $fixture = realpath(__DIR__ . '/../../fixtures/app');

    $tester = new CommandTester($app->find('make:event'));
    $tester->execute(['name' => '../../Escaped']);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('Invalid class name')
        ->and(File::exists($fixture . '/Escaped.php'))->toBeFalse()
        ->and(File::exists($fixture . '/src/Escaped.php'))->toBeFalse()
        ->and(File::exists(dirname($fixture) . '/Escaped.php'))->toBeFalse()
        ->and(File::exists(dirname($fixture, 2) . '/Escaped.php'))->toBeFalse()
        ->and(File::exists(Path::src('Events/../../Escaped.php')))->toBeFalse();
});

test('make:resource rejects a name with a slash via the validation error, not an exception', function () {
    $app = makeGeneratorsApp();

    $tester = new CommandTester($app->find('make:resource'));
    $tester->execute(['name' => 'foo/bar']);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('Invalid class name')
        ->and(File::exists(Path::src('Http/Resources/foo/bar.php')))->toBeFalse();
});

test('make:test rejects a name carrying a file extension', function () {
    $app = makeGeneratorsApp();

    $tester = new CommandTester($app->find('make:test'));
    $tester->execute(['name' => 'pingTest.php']);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('Invalid class name')
        ->and(File::exists(Path::tests('pingTest.php')))->toBeFalse()
        ->and(File::exists(Path::tests('pingTest.php.php')))->toBeFalse();
});

test('make:listener rejects a code-injection --event value', function () {
    $app = makeGeneratorsApp();

    $tester = new CommandTester($app->find('make:listener'));
    $tester->execute(['name' => 'X', '--event' => 'Foo; }']);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('Invalid event class')
        ->and(File::exists(Path::src('Listeners/X.php')))->toBeFalse();
});

// ---------------------------------------------------------------------------
// make:resource
// ---------------------------------------------------------------------------

test('make:resource writes a Resource class', function () {
    $app = makeGeneratorsApp();

    $tester = new CommandTester($app->find('make:resource'));
    $tester->execute(['name' => 'UserResource']);

    $generated = Path::src('Http/Resources/UserResource.php');

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Resource created successfully')
        ->and(File::exists($generated))->toBeTrue();

    $contents = File::get($generated);
    expect($contents)
        ->toContain('declare(strict_types=1);')
        ->toContain('namespace App\\Http\\Resources;')
        ->toContain('use Ions\\Http\\Resource;')
        ->toContain('class UserResource extends Resource')
        ->toContain('public function toArray(Request $request): array')
        ->and($contents)->not->toContain('{{ class }}');

    expectLintOk($generated);
});

test('make:resource --collection writes a ResourceCollection class wired to its resource', function () {
    $app = makeGeneratorsApp();

    $tester = new CommandTester($app->find('make:resource'));
    $tester->execute(['name' => 'UserCollection', '--collection' => true]);

    $generated = Path::src('Http/Resources/UserCollection.php');

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Wiring collection to UserResource::class')
        ->and(File::exists($generated))->toBeTrue();

    $contents = File::get($generated);
    expect($contents)
        ->toContain('declare(strict_types=1);')
        ->toContain('namespace App\\Http\\Resources;')
        ->toContain('use Ions\\Http\\ResourceCollection;')
        ->toContain('class UserCollection extends ResourceCollection')
        ->toContain('parent::__construct($items, UserResource::class);')
        ->and($contents)->not->toContain('{{ resource }}');

    expectLintOk($generated);
});

test('make:resource refuses to overwrite without --force and overwrites with it', function () {
    $app = makeGeneratorsApp();
    $command = $app->find('make:resource');

    (new CommandTester($command))->execute(['name' => 'UserResource']);

    $generated = Path::src('Http/Resources/UserResource.php');
    File::put($generated, '<?php // sentinel');

    $refused = new CommandTester($command);
    $refused->execute(['name' => 'UserResource']);

    expect($refused->getStatusCode())->toBe(1)
        ->and($refused->getDisplay())->toContain('already exists')
        ->and(File::get($generated))->toBe('<?php // sentinel');

    $forced = new CommandTester($command);
    $forced->execute(['name' => 'UserResource', '--force' => true]);

    expect($forced->getStatusCode())->toBe(0)
        ->and(File::get($generated))->toContain('class UserResource extends Resource');
});

// ---------------------------------------------------------------------------
// make:request
// ---------------------------------------------------------------------------

test('make:request writes a FormRequest class with rules and authorize', function () {
    $app = makeGeneratorsApp();

    $tester = new CommandTester($app->find('make:request'));
    $tester->execute(['name' => 'StoreUserRequest']);

    $generated = Path::src('Http/Requests/StoreUserRequest.php');

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Request created successfully')
        ->and(File::exists($generated))->toBeTrue();

    $contents = File::get($generated);
    expect($contents)
        ->toContain('declare(strict_types=1);')
        ->toContain('namespace App\\Http\\Requests;')
        ->toContain('use Ions\\Http\\FormRequest;')
        ->toContain('class StoreUserRequest extends FormRequest')
        ->toContain('public function rules(): array')
        ->toContain('public function authorize(): bool');

    expectLintOk($generated);
});

test('make:request refuses to overwrite an existing file without --force', function () {
    $app = makeGeneratorsApp();
    $command = $app->find('make:request');

    (new CommandTester($command))->execute(['name' => 'StoreUserRequest']);

    $refused = new CommandTester($command);
    $refused->execute(['name' => 'StoreUserRequest']);

    expect($refused->getStatusCode())->toBe(1)
        ->and($refused->getDisplay())->toContain('already exists');
});

// ---------------------------------------------------------------------------
// make:job
// ---------------------------------------------------------------------------

test('make:job writes a Job class extending Ions\\Queue\\Job', function () {
    $app = makeGeneratorsApp();

    $tester = new CommandTester($app->find('make:job'));
    $tester->execute(['name' => 'SendWelcomeJob']);

    $generated = Path::src('Jobs/SendWelcomeJob.php');

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Job created successfully')
        ->and(File::exists($generated))->toBeTrue();

    $contents = File::get($generated);
    expect($contents)
        ->toContain('declare(strict_types=1);')
        ->toContain('namespace App\\Jobs;')
        ->toContain('use Ions\\Queue\\Job;')
        ->toContain('class SendWelcomeJob extends Job')
        ->toContain('public function handle(): void');

    expectLintOk($generated);
});

test('make:job refuses to overwrite an existing file without --force', function () {
    $app = makeGeneratorsApp();
    $command = $app->find('make:job');

    (new CommandTester($command))->execute(['name' => 'SendWelcomeJob']);

    $refused = new CommandTester($command);
    $refused->execute(['name' => 'SendWelcomeJob']);

    expect($refused->getStatusCode())->toBe(1)
        ->and($refused->getDisplay())->toContain('already exists');
});

// ---------------------------------------------------------------------------
// make:event
// ---------------------------------------------------------------------------

test('make:event writes a plain event class with a constructor', function () {
    $app = makeGeneratorsApp();

    $tester = new CommandTester($app->find('make:event'));
    $tester->execute(['name' => 'UserRegistered']);

    $generated = Path::src('Events/UserRegistered.php');

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Event created successfully')
        ->and(File::exists($generated))->toBeTrue();

    $contents = File::get($generated);
    expect($contents)
        ->toContain('declare(strict_types=1);')
        ->toContain('namespace App\\Events;')
        ->toContain('class UserRegistered')
        ->toContain('public function __construct(')
        ->and($contents)->not->toContain('extends');

    expectLintOk($generated);
});

test('make:event refuses to overwrite an existing file without --force', function () {
    $app = makeGeneratorsApp();
    $command = $app->find('make:event');

    (new CommandTester($command))->execute(['name' => 'UserRegistered']);

    $refused = new CommandTester($command);
    $refused->execute(['name' => 'UserRegistered']);

    expect($refused->getStatusCode())->toBe(1)
        ->and($refused->getDisplay())->toContain('already exists');
});

// ---------------------------------------------------------------------------
// make:listener
// ---------------------------------------------------------------------------

test('make:listener writes a listener with an untyped handle by default', function () {
    $app = makeGeneratorsApp();

    $tester = new CommandTester($app->find('make:listener'));
    $tester->execute(['name' => 'SendWelcomeEmail']);

    $generated = Path::src('Listeners/SendWelcomeEmail.php');

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Listener created successfully')
        ->and(File::exists($generated))->toBeTrue();

    $contents = File::get($generated);
    expect($contents)
        ->toContain('declare(strict_types=1);')
        ->toContain('namespace App\\Listeners;')
        ->toContain('class SendWelcomeEmail')
        ->toContain('public function handle(object $event): void');

    expectLintOk($generated);
});

test('make:listener --event type-hints the event class', function () {
    $app = makeGeneratorsApp();

    $tester = new CommandTester($app->find('make:listener'));
    $tester->execute(['name' => 'SendWelcomeEmail', '--event' => 'UserRegistered']);

    $generated = Path::src('Listeners/SendWelcomeEmail.php');
    expect($tester->getStatusCode())->toBe(0);

    $contents = File::get($generated);
    expect($contents)
        ->toContain('use App\\Events\\UserRegistered;')
        ->toContain('public function handle(UserRegistered $event): void');

    expectLintOk($generated);
});

test('make:listener --event accepts a fully-qualified event class', function () {
    $app = makeGeneratorsApp();

    $tester = new CommandTester($app->find('make:listener'));
    $tester->execute(['name' => 'LogRequest', '--event' => 'Ions\\Events\\RequestHandled']);

    $generated = Path::src('Listeners/LogRequest.php');
    expect($tester->getStatusCode())->toBe(0);

    $contents = File::get($generated);
    expect($contents)
        ->toContain('use Ions\\Events\\RequestHandled;')
        ->toContain('public function handle(RequestHandled $event): void');
});

test('make:listener refuses to overwrite an existing file without --force', function () {
    $app = makeGeneratorsApp();
    $command = $app->find('make:listener');

    (new CommandTester($command))->execute(['name' => 'SendWelcomeEmail']);

    $refused = new CommandTester($command);
    $refused->execute(['name' => 'SendWelcomeEmail']);

    expect($refused->getStatusCode())->toBe(1)
        ->and($refused->getDisplay())->toContain('already exists');
});

// ---------------------------------------------------------------------------
// make:test
// ---------------------------------------------------------------------------

test('make:test writes a feature test extending Ions\\Testing\\TestCase into host tests/', function () {
    $app = makeGeneratorsApp();

    $tester = new CommandTester($app->find('make:test'));
    $tester->execute(['name' => 'PingTest']);

    $generated = Path::tests('PingTest.php');

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Test created successfully')
        ->and(File::exists($generated))->toBeTrue();

    $contents = File::get($generated);
    expect($contents)
        ->toContain('declare(strict_types=1);')
        ->toContain('use Ions\\Testing\\TestCase;')
        ->toContain('class PingTest extends TestCase')
        ->toContain("protected string \$basePath = __DIR__ . '/..';")
        ->toContain('public function test_');

    expectLintOk($generated);
});

test('make:test --unit writes a plain PHPUnit test without kernel boot', function () {
    $app = makeGeneratorsApp();

    $tester = new CommandTester($app->find('make:test'));
    $tester->execute(['name' => 'MathTest', '--unit' => true]);

    $generated = Path::tests('MathTest.php');

    expect($tester->getStatusCode())->toBe(0)
        ->and(File::exists($generated))->toBeTrue();

    $contents = File::get($generated);
    expect($contents)
        ->toContain('declare(strict_types=1);')
        ->toContain('use PHPUnit\\Framework\\TestCase;')
        ->toContain('class MathTest extends TestCase')
        ->and($contents)->not->toContain('Ions\\Testing')
        ->and($contents)->not->toContain('$basePath');

    expectLintOk($generated);
});

// ---------------------------------------------------------------------------
// make:factory
// ---------------------------------------------------------------------------

test('make:factory writes a factory class inferring the model from the name', function () {
    $app = makeGeneratorsApp();

    $tester = new CommandTester($app->find('make:factory'));
    $tester->execute(['name' => 'UserFactory']);

    $generated = Path::src('Factories/UserFactory.php');

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Factory created successfully')
        ->and(File::exists($generated))->toBeTrue();

    $contents = File::get($generated);
    expect($contents)
        ->toContain('declare(strict_types=1);')
        ->toContain('namespace App\\Factories;')
        ->toContain('use Ions\\Database\\Factory;')
        ->toContain('class UserFactory extends Factory')
        ->toContain('protected string $model = \\App\\User::class;')
        ->toContain('protected function definition(): array')
        ->and($contents)->not->toContain('{{');

    expectLintOk($generated);
});

test('make:factory --model overrides the inferred model FQCN', function () {
    $app = makeGeneratorsApp();

    $tester = new CommandTester($app->find('make:factory'));
    $tester->execute(['name' => 'WidgetFactory', '--model' => 'IonsFixture\\Models\\Widget']);

    $generated = Path::src('Factories/WidgetFactory.php');
    expect($tester->getStatusCode())->toBe(0);

    expect(File::get($generated))
        ->toContain('protected string $model = \\IonsFixture\\Models\\Widget::class;');

    expectLintOk($generated);
});

test('make:factory rejects an invalid --model value', function () {
    $app = makeGeneratorsApp();

    $tester = new CommandTester($app->find('make:factory'));
    $tester->execute(['name' => 'WidgetFactory', '--model' => 'Bad Model;']);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('Invalid model class')
        ->and(File::exists(Path::src('Factories/WidgetFactory.php')))->toBeFalse();
});

test('make:test refuses to overwrite an existing file without --force', function () {
    $app = makeGeneratorsApp();
    $command = $app->find('make:test');

    (new CommandTester($command))->execute(['name' => 'PingTest']);

    $refused = new CommandTester($command);
    $refused->execute(['name' => 'PingTest']);

    expect($refused->getStatusCode())->toBe(1)
        ->and($refused->getDisplay())->toContain('already exists');
});
