<?php

namespace Ions\Foundation;

use BadMethodCallException;
use Ions\Bundles\Localization;
use Ions\Http\RequestInput;
use Ions\Security\SecurityHeaders;
use Ions\Support\JsonResponse;
use Ions\Support\Request;
use Ions\Support\Response;
use JetBrains\PhpStorm\NoReturn;
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

    #[NoReturn] protected function unauthorizedResponse($response): void
    {
        $this->returnStructure($response, ResponseAlias::HTTP_UNAUTHORIZED);
    }

    #[NoReturn] private function returnStructure($error, $status): void
    {
        $data = [];
        $result = [
            'status_code' => $status,
            'success' => false,
            'error' => $error,
            'data' => $data
        ];

        $json_response = new JsonResponse($result, $status);
        $json_response->setEncodingOptions($json_response->getEncodingOptions() | JSON_PRETTY_PRINT);

        $response_info = $this->response;
        $response_info->setStatusCode($status);
        $response_info->setContent($json_response->getContent());
        SecurityHeaders::apply($response_info);
        $response_info->send();
        exit();
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

    public function routeMethod($method, $callback): void
    {
        if ($callback !== null && $this->request_method === strtoupper($method)) {
            $callback();
        }
    }

    #[NoReturn] public function notFoundResponse($response): void
    {
        $this->returnStructure($response, ResponseAlias::HTTP_NOT_FOUND);
    }

    #[NoReturn] protected function display($jsonResponse): void
    {
        if (!is_string($jsonResponse)) {
            abort(500, 'Data send to api must be Json type.');
        }

        $response = Kernel::response();
        $response->setContent($jsonResponse);
        if (!$response->headers->has('Content-Type')) {
            $response->headers->set('Content-Type', 'application/json');
        }
        SecurityHeaders::apply($response);
        $response->send();
        exit();
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
