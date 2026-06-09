<?php

namespace Ions\Http;

use Ions\Support\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ExceptionHandler
{
    public function render(Throwable $e, Request $request): Response
    {
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
