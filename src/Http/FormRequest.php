<?php

declare(strict_types=1);

namespace Ions\Http;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Ions\Bundles\Path;
use Ions\Foundation\Kernel;
use Ions\Support\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Typed, self-validating request object.
 *
 * Subclasses declare their validation {@see rules()} and, optionally, an
 * {@see authorize()} gate. A failed authorization throws a 403; a failed
 * validation throws Illuminate's {@see ValidationException}, which the
 * {@see ExceptionHandler} renders content-negotiated (10.3): API/JSON
 * requests get a 422 JSON error bag; web (HTML) requests get a 302 redirect
 * back with the errors and input flashed for errors()/old().
 *
 * Ergonomic usage inside a controller:
 *
 *   $data = StoreUserRequest::validate($request);
 *
 * @phpstan-consistent-constructor
 */
abstract class FormRequest
{
    public function __construct(protected Request $request)
    {
    }

    /**
     * The validation rules for the request.
     *
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    /**
     * Determine whether the current request is authorized. Defaults to true.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * Build the form request and return the validated data in one call.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException When validation fails (rendered as 422).
     * @throws AccessDeniedHttpException When authorize() returns false (403).
     */
    public static function validate(Request $request): array
    {
        return (new static($request))->validated();
    }

    /**
     * The current request instance.
     */
    public function request(): Request
    {
        return $this->request;
    }

    /**
     * Run authorization + validation and return the validated input.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     * @throws AccessDeniedHttpException
     */
    public function validated(): array
    {
        if (!$this->authorize()) {
            throw new AccessDeniedHttpException('This action is unauthorized.');
        }

        /** @var array<string, mixed> $input */
        $input = $this->request->all();

        $validator = $this->factory()->make($input, $this->rules(), $this->messages());

        /** @var array<string, mixed> $validated */
        $validated = $validator->validate();

        return $validated;
    }

    /**
     * Build an Illuminate validation factory wired to the framework's
     * translator and (when available) the database presence verifier.
     */
    protected function factory(): Factory
    {
        $locale = (string) config('app.localization.locale', 'en');

        $fileLoader = new FileLoader(new Filesystem(), Path::locale());
        $translator = new Translator($fileLoader, $locale);
        $factory = new Factory($translator);

        $app = Kernel::app();
        if ($app->has('db')) {
            $db = $app->get('db');
            if (is_object($db) && method_exists($db, 'getDatabaseManager')) {
                /** @var \Illuminate\Database\DatabaseManager $manager */
                $manager = $db->getDatabaseManager();
                $factory->setPresenceVerifier(new DatabasePresenceVerifier($manager));
            }
        }

        return $factory;
    }
}
