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
    protected string $locale_folder = 'web';
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

        $config_locale = config('app.localization.locale', $this->locale);
        Localization::init($this->locale_folder, $config_locale);
        $trans_json = Localization::localeJson($config_locale);

        $allow_templates = config('app.templates', ['twig']);
        if (in_array('twig', $allow_templates, true)) {
            $this->TwigInit();
            !$trans_json ?: $this->twig->addGlobal('tJson', $trans_json);
        }

        if (in_array('smarty', $allow_templates, true)) {
            $this->smartyInit();
            !$trans_json ?: $this->smarty->assignGlobal('tJson', $trans_json);
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
     * @throws \BadMethodCallException
     */
    public function __call(string $method, array $parameters)
    {
        throw new BadMethodCallException(sprintf(
            'Method %s::%s does not exist.', static::class, $method
        ));
    }
}
