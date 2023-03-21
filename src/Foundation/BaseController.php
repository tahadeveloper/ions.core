<?php

namespace Ions\Foundation;

use BadMethodCallException;
use Ions\Bundles\Localization;
use Ions\Support\Request;
use Ions\Support\Session;
use Ions\Traits\Smarty;
use Ions\Traits\Twig;

abstract class BaseController implements BluePrint
{
    use Twig, Smarty;

    public Session $session;
    protected string $localeFolder = 'web';
    protected string $locale = 'en';

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
        /** @noinspection PhpUndefinedFieldInspection */
        Localization::init(($this->localeFolder ?? $this->locale_folder), $configLocale);
        $transJson = Localization::localeJson($configLocale);

        $allowTemplates = config('app.templates', ['twig']);
        if (in_array('twig', $allowTemplates, true)) {
            $this->TwigInit();
            !$transJson ?: $this->twig->addGlobal('tJson', $transJson);
        }

        if (in_array('smarty', $allowTemplates, true)) {
            $this->smartyInit();
            !$transJson ?: $this->smarty->assignGlobal('tJson', $transJson);
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
     * Execute an action on the controller.
     *
     * @param string $method
     * @param array $parameters
     * @return void
     * @noinspection PhpUnused
     */
    public function callAction(string $method, array $parameters): void
    {
        $this->{$method}(...array_values($parameters));
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
