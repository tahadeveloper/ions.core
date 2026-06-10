<?php

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem;
use Illuminate\Translation;
use Illuminate\Validation;
use Ions\Bundles\Localization;
use Ions\Bundles\Logs;
use Ions\Bundles\Path;
use Ions\Foundation\Config;
use Ions\Foundation\Kernel;
use Ions\Support\Arr;
use Ions\Support\DB;
use Ions\Support\JsonResponse;
use Ions\Traits\Twig;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\NativeSessionTokenStorage;
use Symfony\Component\Translation\Translator;

if (!function_exists('ionCsrfManager')) {
    /**
     * Resolve the shared CSRF token manager from the container when available,
     * falling back to a native-session manager for contexts where the container
     * has not been booted (e.g. early bootstrapping or isolated scripts).
     *
     * @return \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface
     */
    function ionCsrfManager(): \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface
    {
        try {
            $app = Kernel::app();
            if ($app->has('csrf')) {
                return $app->get('csrf');
            }
            // No explicit csrf binding but a framework session is available:
            // back the manager with the session token storage (single source of truth).
            if ($app->has('request_stack')) {
                /** @var \Symfony\Component\HttpFoundation\RequestStack $stack */
                $stack = $app->get('request_stack');
                return new CsrfTokenManager(
                    new UriSafeTokenGenerator(),
                    new \Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage($stack)
                );
            }
        } catch (\Throwable) {
            // container not booted / binding absent — fall back to native storage
        }
        return new CsrfTokenManager(
            new UriSafeTokenGenerator(),
            new NativeSessionTokenStorage()
        );
    }
}

if (!function_exists('csrfToken')) {
    /**
     * Generate a CSRF token string for the given form name.
     *
     * The default form name is 'web', which matches the tokenId used by
     * CsrfMiddleware.  A no-arg call — csrfToken() — therefore produces a
     * token that the middleware will accept out of the box.
     *
     * @param string $form_name
     * @return string
     */
    function csrfToken(string $form_name = 'web'): string
    {
        return ionCsrfManager()->getToken($form_name)->getValue();
    }
}

if (!function_exists('ionToken')) {
    /**
     * Render a hidden CSRF input element for the given form name.
     *
     * The default form name is 'web', matching the tokenId used by
     * CsrfMiddleware, so ionToken() (no arg) works with the web pipeline.
     *
     * @param string $form_name
     * @param string $input_name
     * @return string
     */
    function ionToken(string $form_name = 'web', string $input_name = '_ion_token'): string
    {
        $token = ionCsrfManager()->getToken($form_name)->getValue();
        return '<input type="hidden" value="' . $token . '" name="' . $input_name . '" id="' . $input_name . '"/>';
    }
}

if (!function_exists('csrfCheck')) {
    /**
     * Validate a CSRF token for the given id and, on success, remove it.
     *
     * The default id is 'web', matching the tokenId used by CsrfMiddleware.
     *
     * @param string $id
     * @param string $token
     * @param int    $request  When 1 the token is read from the current request
     *                         input field ($input_name); pass 0 to supply $token
     *                         directly.
     * @param string $input_name
     * @return bool
     */
    function csrfCheck(string $id = 'web', string $token = '', int $request = 1, string $input_name = '_ion_token'): bool
    {
        $csrfManager = ionCsrfManager();
        if ($request === 1) {
            $token = Kernel::request()->get($input_name);
        }

        $csrf_token = new CsrfToken($id, $token);
        if ($csrfManager->isTokenValid($csrf_token)) {
            $csrfManager->removeToken($id);
            return true;
        }
        return false;
    }
}

if (!function_exists('validate')) {
    /**
     * validate inputs to be valid
     * @param array|object $params
     * @param array $rules
     * @param array $messages
     * @return array
     */
    function validate(array|object $params, array $rules, array $messages = []): array
    {
        $locale = config('app.localization.locale', 'en');

        $app = Kernel::app();
        $response = [];

        try {
            $translationDir = Path::locale();
            $filesystem = new Filesystem\Filesystem();
            $fileLoader = new Translation\FileLoader($filesystem, $translationDir);
            $translator = new Translation\Translator($fileLoader, $locale);
            $factory = new Validation\Factory($translator);
            if ($app->has('db')) {
                $presenceVerifier = new Validation\DatabasePresenceVerifier($app->get('db')?->getDatabaseManager());
                $factory->setPresenceVerifier($presenceVerifier);
            }
            // check if params is array or object
            if (is_object($params)) {
                $params = json_decode(json_encode($params, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            }
            $validator = $factory->make($params, $rules, $messages);
            if ($validator->fails()) {
                $errors = $validator->errors();
                $response = $errors->all();
            }
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
            $response = ['validation not working, no database connected.' . $e->getMessage()];
        } catch (JsonException $e) {
            $response = ['validation not working, Json error.' . $e->getMessage()];
        }

        return $response;
    }
}

if (!function_exists('appUrl')) {
    /**
     * create csrf token
     * @param string $path
     * @return string
     */
    function appUrl(string $path = ''): string
    {
        return config('app.app_url') . $path;
    }
}

if (!function_exists('debugQuery')) {
    /**
     * @return array
     */
    function debugQuery(): array
    {
        return DB::connection()->getQueryLog();
    }
}

if (!function_exists('newMailer')) {
    /**
     * @param array|string $emails
     * @param string $subject
     * @param $body
     * @return bool
     */
    function newMailerDsn(array|string $emails, string $subject, $body): bool
    {
        try {
            /** @var \Symfony\Component\Mailer\MailerInterface $mailer */
            $mailer = app('mailer');
            $the_email = (new Email())
                ->from(new Address(env('MAIL_FROM_ADDRESS', ''), env('MAIL_FROM_NAME', '')))
                ->to($emails)
                ->subject($subject)
                ->html($body);
            $mailer->send($the_email);
            return true;
        } catch (Throwable $exception) {
            Logs::create('send_mail.log')->error($exception->getMessage(), ['email' => $emails, 'subject' => $subject]);
            return false;
        }
    }
}

if (!function_exists('abort')) {
    /**
     * Throw an HttpException with the given data.
     *
     * @param int $code
     * @param string $message
     * @param array $headers
     * @return never
     *
     */
    function abort(int $code, string $message = '', array $headers = []): never
    {
        throw new HttpException($code, $message, null, $headers);
    }
}

if (!function_exists('signedUrl')) {
    /**
     * Sign an arbitrary URL (relative or absolute) with the container's
     * UrlSigner ('url.signer'). Requires a valid APP_KEY (≥ 32 bytes).
     *
     * @param string $url
     * @param DateTimeInterface|int|null $expiresAt Epoch seconds or a DateTime; null = no expiry.
     * @return string
     */
    function signedUrl(string $url, DateTimeInterface|int|null $expiresAt = null): string
    {
        /** @var \Ions\Security\UrlSigner $signer */
        $signer = Kernel::app()->make('url.signer');

        return $signer->sign($url, $expiresAt);
    }
}

if (!function_exists('signedRoute')) {
    /**
     * Generate a signed absolute URL for a named route.
     *
     * The route is resolved from Kernel::RouteCollection() via Symfony's
     * UrlGenerator (context mirrors the current request, like the kernel's
     * matcher). $params fill route placeholders; leftovers become query
     * params. The absolute URL is built from config('app.app_url') — the same
     * value the kernel's host gate enforces — so signed links always target
     * the canonical host.
     *
     * @param string $name Route name (4th param of Route::get(), or attribute route name).
     * @param array<string, mixed> $params Placeholder + extra query parameters.
     * @param DateTimeInterface|int|null $expiresAt Epoch seconds or a DateTime; null = no expiry.
     * @return string
     * @throws \Symfony\Component\Routing\Exception\RouteNotFoundException when the route name is unknown.
     */
    function signedRoute(string $name, array $params = [], DateTimeInterface|int|null $expiresAt = null): string
    {
        // The shared collection only holds routes registered outside route
        // files (App\Booting, tests). Routes from routes/{web|api}.php and
        // attributes live in the per-group captures — search those next.
        $routes = Kernel::RouteCollection();
        if ($routes->get($name) === null) {
            foreach (['web', 'api'] as $group) {
                $candidate = Kernel::routesFor($group);
                if ($candidate->get($name) !== null) {
                    $routes = $candidate;
                    break;
                }
            }
        }

        $context = new \Symfony\Component\Routing\RequestContext();
        $context->fromRequest(Kernel::request());

        $generator = new \Symfony\Component\Routing\Generator\UrlGenerator($routes, $context);
        $path = $generator->generate($name, $params, \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_PATH);

        $base = rtrim((string) config('app.app_url', ''), '/');

        return signedUrl($base . $path, $expiresAt);
    }
}

if (!function_exists('toObject')) {
    /**
     * @param array $unhandled
     * @return object|null
     */
    function toObject(array $unhandled): object|null
    {
        try {
            return json_decode(json_encode($unhandled, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
}

if (!function_exists('toJson')) {
    /**
     * @param array $unhandled
     * @param bool $sec_level
     * @return string
     */
    function toJson(array $unhandled, bool $sec_level = false): string
    {
        $handle_arr = [];
        foreach ($unhandled as $key => $value) {
            if (is_array($value) && $sec_level) {
                $handle_arr[$key] = (new JsonResponse($value))->getContent();
            } else {
                $handle_arr[$key] = $value;
            }
        }
        return (new JsonResponse($handle_arr))->getContent();
    }
}

if (!function_exists('display')) {
    /**
     * @param string $json
     * @param bool $continue
     * @return void
     */
    function display(string $json, bool $continue = false): void
    {
        echo $json;
        if (!$continue) {
            exit();
        }
    }
}

if (!function_exists('toString')) {
    /**
     * @param array|string $array
     * @return string
     */
    function toString(array|string $array): string
    {
        if (is_array($array)) {
            $array = Arr::flatten($array);
            return implode(',', $array);
        }
        return $array;
    }
}

if (!function_exists('config')) {
    /**
     * Get / set the specified configuration value.
     *
     * If an array is passed as the key, we will assume you want to set an array of values.
     *
     * @param string|array<string,mixed>|null $key
     * @param mixed $default
     * @return Config|mixed
     */
    function config(string|array|null $key = null, mixed $default = null)
    {
        if (is_null($key)) {
            return Kernel::config();
        }

        if (is_array($key)) {
            Kernel::config()->set($key);
        }

        return Kernel::config()->get($key, $default);
    }
}

if (!function_exists('session')) {
    /**
     * Get / set session values, or resolve the session manager.
     *
     * Mirrors the config() helper's overloads:
     *   session()            -> the SessionManager
     *   session('key')       -> get a value (with optional default)
     *   session(['k' => 'v']) -> put one or more values
     *
     * @param string|array<string,mixed>|null $key
     * @param mixed $default
     * @return \Ions\Session\SessionManager|mixed
     */
    function session(string|array|null $key = null, mixed $default = null)
    {
        $manager = Kernel::app()->get('session');
        /** @var \Ions\Session\SessionManager $manager */

        if (is_null($key)) {
            return $manager;
        }

        if (is_array($key)) {
            $manager->start();
            foreach ($key as $k => $value) {
                $manager->put((string) $k, $value);
            }
            return $manager;
        }

        return $manager->get($key, $default);
    }
}

if (!function_exists('cache')) {
    /**
     * Interact with the shared cache.
     *
     * Mirrors the config()/session() overloads:
     *   cache()                 -> the default cache repository
     *   cache('key')            -> get a value (with optional default)
     *   cache(['k' => 'v'], $t) -> put one or more values (TTL in seconds, null = forever)
     *
     * @param string|array<string,mixed>|null $key
     * @param mixed $default When getting: the fallback. When putting: the TTL in seconds.
     * @return \Illuminate\Contracts\Cache\Repository|mixed
     */
    function cache(string|array|null $key = null, mixed $default = null)
    {
        /** @var \Illuminate\Cache\CacheManager $manager */
        $manager = Kernel::app()->get('cache');
        $repository = $manager->store();

        if (is_null($key)) {
            return $repository;
        }

        if (is_array($key)) {
            foreach ($key as $k => $value) {
                if ($default === null) {
                    $repository->forever((string) $k, $value);
                } else {
                    $repository->put((string) $k, $value, $default);
                }
            }

            return $repository;
        }

        return $repository->get($key, $default);
    }
}

if (!function_exists('event')) {
    /**
     * Dispatch an event through the shared dispatcher.
     *
     *   event($eventObject)            -> dispatch an event object (class name = event name)
     *   event('name', [$a, $b])        -> dispatch a named event with a payload
     *
     * Listeners are invoked synchronously. Returns the array of listener
     * responses (or null when a listener halts propagation), mirroring
     * Illuminate's dispatcher.
     *
     * @param object|string $event
     * @param array<int,mixed> $payload
     * @return array<int,mixed>|null
     */
    function event(object|string $event, array $payload = []): ?array
    {
        /** @var \Illuminate\Contracts\Events\Dispatcher $dispatcher */
        $dispatcher = Kernel::app()->get('events');

        return $dispatcher->dispatch($event, $payload);
    }
}

if (!function_exists('listen')) {
    /**
     * Register an event listener with the shared dispatcher.
     *
     * @param string|array<int,string> $events
     * @param \Closure|string|array<int,string>|null $listener
     * @return void
     */
    function listen(string|array $events, \Closure|string|array|null $listener = null): void
    {
        /** @var \Illuminate\Contracts\Events\Dispatcher $dispatcher */
        $dispatcher = Kernel::app()->get('events');
        $dispatcher->listen($events, $listener);
    }
}

if (!function_exists('dispatch')) {
    /**
     * Dispatch a job onto the queue.
     *
     * The job is pushed onto its target connection (the job's own
     * ->onConnection()/->onQueue() take precedence, otherwise the configured
     * default connection). On the 'sync' connection the job runs immediately;
     * on persistent connections (e.g. 'database') it is stored for a worker.
     *
     * @param object $job
     * @return mixed The queue driver's push result (e.g. the inserted job id).
     */
    function dispatch(object $job): mixed
    {
        /** @var \Illuminate\Queue\QueueManager $manager */
        $manager = Kernel::app()->get('queue');

        $connection = property_exists($job, 'connection') ? $job->connection : null;
        $queue = property_exists($job, 'queue') ? $job->queue : null;

        return $manager->connection(is_string($connection) ? $connection : null)
            ->push($job, '', is_string($queue) ? $queue : null);
    }
}

if (!function_exists('trans')) {
    /**
     * @param string|null $key
     * @param array $replace
     * @param string|null $domain
     * @param string|null $locale
     * @return Translator|string
     */
    function trans(string|null $key = null, array $replace = [], string|null $domain = null, string|null $locale = null): Translator|string
    {
        if (!isset(Localization::$localization)) {
            abort(501, 'Must add folder and option in config before use it.');
        }

        if (is_null($key)) {
            return Localization::$localization;
        }

        return Localization::$localization->trans($key, $replace, $domain, $locale);
    }
}

if (!function_exists('appSetLocale')) {
    /**
     * Set the current application locale.
     *
     * @param string $locale
     * @return void
     */
    function appSetLocale(string $locale)
    {
        config()?->set('app.localization.locale', $locale);
    }
}

if (!function_exists('appGetLocale')) {
    /**
     * Set the current application locale.
     *
     * @return string|null
     */
    function appGetLocale(): string|null
    {
        return config('app.localization.locale');
    }
}

if (!function_exists('app')) {
    /**
     * Get the available container instance.
     *
     * @param string|null $abstract
     * @param array $parameters
     * @return mixed|Application
     * @throws BindingResolutionException
     */
    function app(string|null $abstract = null, array $parameters = []): mixed
    {
        if (is_null($abstract)) {
            return Kernel::app();
        }

        return Kernel::app()->make($abstract, $parameters);
    }
}

if (!function_exists('datatableCols')) {
    /**
     * @param array $columns
     * @return string
     */
    function datatableCols(array $columns): string
    {
        $dt_cols = [];
        foreach ($columns as $column) {
            $dt_cols[] = ['data' => $column];
        }

        return toJson($dt_cols);
    }
}

if (!function_exists('render')) {
    /**
     * only work with twig
     * @param string $name
     * @param array $parameters
     * @param string[] $locales
     * @return void
     */
    function render(string $name, array $parameters = [], array $locales = ['locale' => 'en', 'folder' => 'web']): void
    {
        $config_locale = config('app.localization.locale', $locales['locale']);
        Localization::init($locales['folder'], $config_locale);
        $trans_json = Localization::localeJson($config_locale);

        $allow_templates = config('app.templates', ['twig']);
        if (in_array('twig', $allow_templates, true)) {
            $twig = (new class () {
                use Twig;
            });
            $twig->TwigInit();
            !$trans_json ?: $twig->twig->addGlobal('tJson', $trans_json);

            $twig->twig->display($name, $parameters);
        }
    }
}
