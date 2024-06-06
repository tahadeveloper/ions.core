<?php

namespace Ions\Foundation;

use BadMethodCallException;
use Ions\Bundles\AppKeys;
use Ions\Bundles\Localization;
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

        /*$response_info = $this->response;
        $response_info->headers->set('Content-Type', 'application/json');
        $response_info->headers->set('Access-Control-Allow-Origin', "*");
        $response_info->headers->set('Access-Control-Allow-Credentials', 'true');
        $response_info->headers->set('Access-Control-Max-Age', '3600');
        $response_info->headers->set('Access-Control-Allow-Headers',
            'X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method,Access-Control-Request-Headers, Authorization');
        $response_info->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response_info->send();
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method === "OPTIONS") {
            $response_info->headers->set('HTTP/1.1', "200 OK");
            $response_info->send();
            die();
        }*/

        $this->request = Kernel::request();


        if (!$this->isAuthorized()) {
            //$this->unauthorizedResponse();
            $this->display(toJson([
                'status' => 'error',
                'message' => 'Not authorized!',
                'code' => ResponseAlias::HTTP_UNAUTHORIZED
            ]));
        }

        RegisterDB::boot();

        $this->request_method = $this->request->getMethod() ?? $method;

        $global_inputs = $this->request->all();
        $this->inputs = (object)($global_inputs ?: $this->renderRequest($this->request_method));

    }

    public function _initState(Request $request): void
    {
        // Implement _initState() method.
    }

    public function _loadInit(Request $request): void
    {
        $config_locale = config('app.localization.locale',$this->locale);
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
        $response_info->send();
        exit();
    }

    private function isAuthorized(): bool
    {
        if (!isset($_SERVER['HTTP_AUTHORIZATION']) && empty($this->request->header('Authorization'))) {
            return false;
        }

        @list($authType, $authData) =
            explode(" ", $_SERVER['HTTP_AUTHORIZATION'] ?? $this->request->header('Authorization'), 2);

        if ($authType !== 'Bearer') {
            $this->unauthorizedResponse(['error' => 'No key attach!']);
        }

        $status = AppKeys::validateJWT($authData);

        return $status['success'];
    }

    private function renderRequest($method)
    {
        $php_input = 'php://input';
        $json_response = new JsonResponse();
        switch ($method) {
            case 'POST':
                $file_inputs = file_get_contents($php_input);
                $vars = $json_response->setContent($file_inputs)->getContent();
                if ($vars === null) {
                    $vars = json_decode(json_encode($_POST), JSON_FORCE_OBJECT, 512);
                }
                if (isset($_FILES) && !empty($_FILES)) {
                    $vars = (object)$vars;
                    $vars->files = $_FILES;
                }
                break;
            case 'DELETE':
            case 'GET':
                $vars = $_GET;
                $vars = (object)$vars;
                break;
            case 'PUT':
                $vars = json_decode(file_get_contents($php_input), JSON_FORCE_OBJECT, 512);
                if (empty($vars)) {
                    parse_str(file_get_contents($php_input), $vars);
                    $vars = (object)$vars;
                }
                break;
            default:
                $vars = (object)array();
                break;
        }
        return $vars;
    }

    #[NoReturn] public function notFoundResponse($response): bool|array|string
    {
        $this->returnStructure($response, ResponseAlias::HTTP_NOT_FOUND);
    }

    public function routeMethod($method, $callback): void
    {
        if ($callback !== null && $this->request_method === strtoupper($method)) {
            $callback();
        }
    }

    #[NoReturn] protected function display($jsonResponse): void
    {
        if(!is_string($jsonResponse)){
            abort(500,'Data send to api must be Json type.');
        }

        echo $jsonResponse;
        exit();
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
