<?php

declare(strict_types=1);

namespace Ions\Http;

use Illuminate\Validation\ValidationException;
use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ExceptionHandler
{
    public function render(Throwable $e, Request $request): Response
    {
        // Illuminate validation failures map to 422 with a structured error bag.
        if ($e instanceof ValidationException) {
            return $this->renderValidation($e, $request);
        }

        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
        $debug = (bool) env('APP_DEBUG', false);

        // HttpException messages are deliberate/client-facing; generic exceptions
        // must NOT leak their message in production.
        $isHttp = $e instanceof HttpExceptionInterface;
        $clientMessage = $isHttp
            ? ($e->getMessage() !== '' ? $e->getMessage() : (Response::$statusTexts[$status] ?? 'Error'))
            : ($debug ? $e->getMessage() : (Response::$statusTexts[$status] ?? 'Server Error'));

        if ($request->wantsJson() || $request->segment(1) === 'api') {
            $extra = $debug && !$isHttp
                ? ['exception' => $e::class, 'trace' => explode("\n", $e->getTraceAsString())]
                : [];
            return Json::error($clientMessage, $status, $extra);
        }

        return $this->html($clientMessage, $status, $e, $debug);
    }

    /**
     * Render an Illuminate ValidationException as a 422 response. API/JSON
     * requests receive {message, errors}; web requests fall back to HTML.
     */
    private function renderValidation(ValidationException $e, Request $request): Response
    {
        $status = $e->status; // 422 by default
        /** @var array<string, list<string>> $errors */
        $errors = $e->errors();
        $message = $e->getMessage() !== '' ? $e->getMessage() : 'The given data was invalid.';

        if ($request->wantsJson() || $request->segment(1) === 'api') {
            return Json::error($message, $status, ['errors' => $errors]);
        }

        return $this->html($message, $status, $e, (bool) env('APP_DEBUG', false));
    }

    private function html(string $message, int $status, Throwable $e, bool $debug): Response
    {
        if ($debug) {
            $body = sprintf(
                "<h1>%d %s</h1><pre>%s\n\n%s</pre>",
                $status,
                htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($e::class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($e->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        } else {
            $body = sprintf('<h1>%d %s</h1>', $status, htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        return new Response($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
