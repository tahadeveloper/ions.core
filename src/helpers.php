<?php

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Ions\Bundles\Localization;
use Ions\Bundles\Logs;
use Ions\Bundles\Path;
use Ions\Foundation\Config;
use Ions\Foundation\Kernel;
use Ions\Support\Arr;
use Ions\Support\DB;
use Ions\Support\JsonResponse;
use Ions\Traits\Twig;
use JetBrains\PhpStorm\NoReturn;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\NativeSessionTokenStorage;
use Illuminate\Validation;
use Illuminate\Filesystem;
use Illuminate\Translation;
use Symfony\Component\Translation\Translator;

if (!function_exists('ionToken')) {
    /**
     * create csrf token
     * @param string $form_name
     * @return string
     */
    function ionToken(string $form_name = ''): string
    {
        // csrf create
        $csrfGenerator = new UriSafeTokenGenerator();
        $csrfStorage = new NativeSessionTokenStorage();
        $csrfManager = new CsrfTokenManager($csrfGenerator, $csrfStorage);

        return '<input type="hidden" value="' . $csrfManager->getToken($form_name) . '" name="_ion_token" id="_ion_token"/>';
    }
}

if (!function_exists('csrfCheck')) {
    /**
     * csrf check
     * @param $id
     * @param string $token
     * @param int $request
     * @return bool
     */
    function csrfCheck($id, string $token = '', int $request = 1): bool
    {
        $csrfGenerator = new UriSafeTokenGenerator();
        $csrfStorage = new NativeSessionTokenStorage();
        $csrfManager = new CsrfTokenManager($csrfGenerator, $csrfStorage);
        if ($request === 1) {
            $token = Kernel::request()->get('_ion_token');
        }

        $is_valid = false;
        $csrf_token = new CsrfToken($id, $token);
        if ($csrfManager->isTokenValid($csrf_token)) {
            $csrfManager->removeToken($id);
            $is_valid = true;
        }
        return $is_valid;
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
            $validator = $factory->make(json_decode(json_encode($params, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR), $rules, $messages);
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
    #[NoReturn] function debugQuery(): array
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

            $transport = Transport::fromDsn('smtp://' . env('MAIL_USERNAME') . ':' . env('MAIL_PASSWORD') . '@' . env('MAIL_HOST') . ':' . env('MAIL_PORT'));
            $mailer = new Mailer($transport);

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
    function abort(int $code, string $message = '', array $headers = [])
    {
        throw new HttpException($code, $message, null, $headers);
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
     * @return object|null
     */
    function toJson(array $unhandled, bool $sec_level = false): string|null
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
    #[NoReturn]
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
     * @param null $key
     * @param mixed|null $default
     * @return Config|mixed|void
     */
    function config($key = null, mixed $default = null)
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

if (!function_exists('trans')) {
    /**
     * @param string|null $key
     * @param array $replace
     * @param string|null $domain
     * @param string|null $locale
     * @return Translator|bool|string
     */
    function trans(string|null $key = null, array $replace = [], string|null $domain = null, string|null $locale = null): Translator|bool|string
    {
        if (Localization::$localization === null) {
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
            $twig = (new class {
                use Twig;
            });
            $twig->TwigInit();
            !$trans_json ?: $twig->twig->addGlobal('tJson', $trans_json);

            $twig->twig->display($name, $parameters);
        }
    }
}