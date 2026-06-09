<?php

namespace Ions\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

final class Json
{
    public static function ok(mixed $data = null, int $status = 200): JsonResponse
    {
        return new JsonResponse(['status' => 'success', 'data' => $data], $status);
    }

    /** @param array<string,mixed> $extra */
    public static function error(string $message, int $status = 400, array $extra = []): JsonResponse
    {
        return new JsonResponse(['status' => 'error', 'message' => $message, 'code' => $status] + $extra, $status);
    }
}
