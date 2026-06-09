<?php

namespace Ions\Foundation;

use BadMethodCallException;
use Ions\Bundles\Localization;
use Ions\Http\RequestInput;
use Ions\Support\JsonResponse;
use Ions\Support\Request;
use Ions\Support\Response;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

abstract class ApiController implements BluePrint
{
    protected string|object|array $inputs;
    protected mixed $request_method;
    protected Request $request;
    protected Response $response;
    protected string $locale_folder = 'api';
    protected string $locale = 'en';

    public function __construct()
    {
        $this->response = Kernel::response();
        $this->request = Kernel::request();

        RegisterDB::boot();

        $this->request_method = $this->request->getMethod();

        $this->inputs = RequestInput::parse($this->request);
    }

    public function _initState(Request $request): void
    {
        // Implement _initState() method.
    }

    public function _loadInit(Request $request): void
    {
        $config_locale = config('app.localization.locale', $this->locale);
        Localization::init($this->locale_folder, $config_locale);
    }

    public function _loadedState(Request $request): void
    {
        // Implement _loadedState() method.
    }

    public function _endState(Request $request): void
    {
        // Implement _endState() method.
    }

    protected function unauthorizedResponse($response): ResponseAlias
    {
        return $this->returnStructure($response, ResponseAlias::HTTP_UNAUTHORIZED);
    }

    private function returnStructure($error, $status): ResponseAlias
    {
        $result = [
            'status_code' => $status,
            'success' => false,
            'error' => $error,
            'data' => [],
        ];

        $json_response = new JsonResponse($result, $status);
        $json_response->setEncodingOptions($json_response->getEncodingOptions() | JSON_PRETTY_PRINT);

        return $json_response;
    }

    /**
     * Return the authenticated user id placed on the request by AuthMiddleware,
     * or null when the request has not been through the auth pipeline.
     */
    protected function authUserId(): ?string
    {
        $value = $this->request->attributes->get('auth_user_id');
        return $value !== null ? (string) $value : null;
    }

    /**
     * Return the resolved Authenticatable user placed on the request by AuthMiddleware
     * (only available when a UserProvider is configured), or null otherwise.
     */
    protected function authUser(): ?\Ions\Auth\Contracts\Authenticatable
    {
        $u = $this->request->attributes->get('auth_user');
        return $u instanceof \Ions\Auth\Contracts\Authenticatable ? $u : null;
    }

    public function routeMethod($method, $callback): void
    {
        if ($callback !== null && $this->request_method === strtoupper($method)) {
            $callback();
        }
    }

    public function notFoundResponse($response): ResponseAlias
    {
        return $this->returnStructure($response, ResponseAlias::HTTP_NOT_FOUND);
    }

    protected function display($jsonResponse): ResponseAlias
    {
        if (!is_string($jsonResponse)) {
            abort(500, 'Data send to api must be Json type.');
        }

        return new ResponseAlias($jsonResponse, 200, ['Content-Type' => 'application/json']);
    }

    /**
     * Execute an action on the controller.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
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
     * @throws \BadMethodCallException
     */
    public function __call(string $method, array $parameters)
    {
        throw new BadMethodCallException(sprintf(
            'Method %s::%s does not exist.',
            static::class,
            $method
        ));
    }
}
