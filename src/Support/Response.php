<?php

declare(strict_types=1);

namespace Ions\Support;

use JsonException;

class Response extends \Illuminate\Http\Response
{
    /**
     * @param mixed                 $data
     * @param array<string, mixed>  $headers
     */
    public function json(mixed $data, int $status = 200, array $headers = [], int $options = 0): Response
    {
        try {
            $jsonData = json_encode($data, JSON_THROW_ON_ERROR | $options);
        } catch (JsonException $e) {
            // Handle the exception, e.g., log the error and return a response with an error message
            // Log::error('JSON encoding error: ' . $e->getMessage());
            return $this->setContent(json_encode(['error' => 'JSON encoding error'], JSON_THROW_ON_ERROR))
                ->setStatusCode(500)
                ->withHeaders($headers);
        }

        return $this->setContent($jsonData)
            ->setStatusCode($status)
            ->withHeaders($headers);
    }
}
