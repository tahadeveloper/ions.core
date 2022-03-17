<?php

namespace Ions\Foundation;

use Ions\Bundles\Localization;
use Ions\Support\JsonResponse;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;
use Closure;
use Exception;
use stdClass;

abstract class ProviderController extends Singleton
{
    protected static array $result;
    protected static int $status;

    /**
     * static call
     * @param Closure|null $callback
     * @return static
     */
    public static function run(Closure $callback = null): self
    {
        if ($callback !== null) {
            $callback();
        }

        static $instance = null;
        if ($instance === null) {
            $instance = new static();
        }

        return $instance;
    }

    public static function _constructStatic(): void
    {
        $locale = config('app.localization.locale', 'en');
        Localization::fileTranslate('provider', $locale,'provider');
    }

    public function toJson(): bool|string
    {
        return (new JsonResponse(static::$result))->getContent();
    }

    public function get(): array
    {
        return static::$result;
    }

    public function toArray(): array
    {
        try {
            return json_decode(json_encode(static::$result, JSON_THROW_ON_ERROR), TRUE, 512, JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function toObject(): object|array
    {
        try {
            return json_decode(json_encode(static::$result, JSON_THROW_ON_ERROR), FALSE, 512, JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            $error_message = new stdClass();
            $error_message->error = $e->getMessage();
            return $error_message;
        }
    }

    protected static function badRequest($response): void
    {
        static::returnStructure([], false, $response, ResponseAlias::HTTP_BAD_REQUEST);
    }

    protected static function serverError($response): void
    {
        if (config('app.app_debug', false)) {
            static::returnStructure([], false, $response, ResponseAlias::HTTP_INTERNAL_SERVER_ERROR);
        } else {
            static::returnStructure([], false, 'server error', ResponseAlias::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    protected static function createdResponse($response): void
    {
        static::returnStructure($response, true, [], ResponseAlias::HTTP_CREATED);
    }

    protected static function successResponse($response): void
    {
        static::returnStructure($response, true);
    }

    protected static function updatedResponse($response): void
    {
        static::returnStructure($response, true, [], ResponseAlias::HTTP_ACCEPTED);
    }

    protected static function deletedResponse($response): void
    {
        static::returnStructure($response, true, [], ResponseAlias::HTTP_ACCEPTED);
    }

    private static function returnStructure(array|object $data, bool $success, $error = [], $status = ResponseAlias::HTTP_OK): void
    {
        static::$result = [
            'status_code' => $status,
            'success' => $success,
            'error' => $error,
            'data' => $data
        ];

        $response = Kernel::response();
        $response->setStatusCode($status);
        $response->send();
    }
}

ProviderController::_constructStatic();