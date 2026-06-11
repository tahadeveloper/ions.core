<?php

declare(strict_types=1);

namespace Ions\Foundation;

use BadMethodCallException;
use Ions\Bundles\Localization;
use Ions\Support\Request;
use Ions\Support\Session;
use Ions\Support\Str;
use Ions\Traits\Twig;
use Ions\View\View;

/**
 * Web controller base. Dispatch lifecycle (see docs/controllers.md):
 *
 *   __construct (container-built — call parent::__construct() when overriding)
 *   → _initState → _loadInit → _loadedState
 *   → boot(...)                                  [optional, method-injected]
 *   → [middleware(): array — sub-pipeline]       [optional, fail-closed]
 *   →   beforeAction($request): ?Response        [optional, non-null short-circuits]
 *   →   action (method-injected: Request, route placeholders, services)
 *   →   afterAction($request, $response): ?Response  [optional, non-null replaces]
 *   → _endState                                  (always runs)
 *
 * The optional hooks are duck-typed via method_exists (like the legacy
 * underscore hooks) — define them on a controller only when needed; this base
 * deliberately ships no no-op defaults so host signatures can't collide.
 */
abstract class BaseController implements BluePrint
{
    use Twig;

    public Session $session;
    protected string $localeFolder = 'web';
    protected string $locale = 'en';

    /**
     * Explicit view folder for $this->view(). When empty the folder is
     * derived from the controller's FQCN (see viewFolder()).
     */
    protected string $viewPath = '';

    public function __construct()
    {
        $this->session = Kernel::session();
        RegisterDB::boot();
    }

    public function _initState(Request $request): void
    {
        // Implement _initState() method.
    }

    /**
     * @internal
     */
    public function _loadInit(Request $request): void
    {
        if ($this->session->has('_super') && isset($this->session->get('_super')['_locale'])) {
            appSetLocale($this->session->get('_super')['_locale']);
        }

        $configLocale = config('app.localization.locale', $this->locale);
        Localization::init($this->localeFolder, $configLocale);
        $transJson = Localization::localeJson($configLocale);

        $allowTemplates = config('app.templates', ['twig']);
        if (in_array('twig', $allowTemplates, true)) {
            $this->TwigInit();
            !$transJson ?: $this->twig->addGlobal('tJson', $transJson);
        }
    }

    public function _loadedState(Request $request): void
    {
        // Implement _loadedState() method.
    }

    public function _endState(Request $request): void
    {
        // Implement _endState() method.
    }

    /**
     * Authorize the current request's user against the gate (10.4):
     * $this->authorize('update', $post). Throws a 403 HttpException when the
     * ability/policy denies — see docs/auth.md "Authorization".
     */
    protected function authorize(string $ability, mixed ...$args): void
    {
        /** @var \Ions\Auth\Gate $gate */
        $gate = Kernel::app()->make('gate');
        $gate->authorize($ability, ...$args);
    }

    /**
     * Controller-relative view (9.2): resolves "{folder}/{name}" through the
     * view() helper (dots -> '/', '.twig' appended) where {folder} is
     * $viewPath when set, otherwise derived from the controller FQCN.
     * Namespaced names ('@admin.users.index') bypass the folder entirely.
     *
     * @param array<string, mixed> $data
     */
    protected function view(string $name, array $data = []): View
    {
        if (str_starts_with($name, '@')) {
            return view($name, $data);
        }

        $folder = $this->viewPath !== '' ? trim($this->viewPath, '/') : $this->viewFolder(static::class);

        return view(($folder !== '' ? $folder . '/' : '') . ltrim($name, '/'), $data);
    }

    /**
     * Derive the view folder for a controller FQCN (pure — unit-testable).
     *
     * Rule (spec 9.2): the folder is the controller's directory path under
     * Http\Controllers, kebab-cased, with the class name itself dropped
     * (Http\Controllers\Admin\UserReports\HomeController -> 'admin/user-reports').
     * A root-level controller maps to its own short name minus the
     * 'Controller' suffix (UsersController -> 'users'). FQCNs without the
     * Http\Controllers marker fall back to the root rule.
     */
    protected function viewFolder(string $class): string
    {
        $marker = 'Http\\Controllers\\';
        $position = strpos($class, $marker);
        $relative = $position === false
            ? Str::afterLast($class, '\\')
            : substr($class, $position + strlen($marker));

        $segments = explode('\\', $relative);
        $shortName = (string) array_pop($segments);

        if ($segments === []) {
            return Str::kebab((string) preg_replace('/Controller$/', '', $shortName));
        }

        return implode('/', array_map(static fn (string $segment): string => Str::kebab($segment), $segments));
    }

    /**
     * Execute an action on the controller.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     * @noinspection PhpUnused
     */
    public function callAction(string $method, array $parameters): mixed
    {
        return $this->{$method}(...array_values($parameters));
    }

    /**
     * Handle calls to missing methods on the controller.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters)
    {
        throw new BadMethodCallException(sprintf('Method %s::%s does not exist.', static::class, $method));
    }
}
