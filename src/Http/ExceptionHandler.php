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
            return $this->withExceptionHeaders(Json::error($clientMessage, $status, $extra), $e);
        }

        return $this->withExceptionHeaders($this->html($clientMessage, $status, $e, $debug, $request), $e);
    }

    /**
     * Propagate HttpException headers (Retry-After on the maintenance 503,
     * Allow on a 405, WWW-Authenticate on a 401, ...) onto the rendered
     * response — deliberate, client-facing metadata must not be lost (10.8).
     */
    private function withExceptionHeaders(Response $response, Throwable $e): Response
    {
        if ($e instanceof HttpExceptionInterface) {
            foreach ($e->getHeaders() as $name => $value) {
                $response->headers->set((string) $name, $value);
            }
        }

        return $response;
    }

    /**
     * Render an Illuminate ValidationException. API/JSON requests receive a
     * 422 {message, errors} payload (unchanged since 7.7); web (HTML)
     * requests get the form flow (10.3): a 302 redirect back to the Referer
     * ('/' fallback) with the errors and the request input flashed for the
     * next render's errors()/old() helpers.
     *
     * This is the single choke point for the web behavior — FormRequest,
     * closures and manual validate() calls that throw ValidationException all
     * land here.
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

        // withInput() reads the failing request (pinned via the Redirector);
        // password-ish fields are excluded per config('app.forms.dont_flash').
        return (new Redirector($request))->back()
            ->withErrors($errors)
            ->withInput();
    }

    private function html(string $message, int $status, Throwable $e, bool $debug, Request $request): Response
    {
        if ($debug) {
            // Rich debug page (source excerpt, redacted request summary,
            // chained exceptions). It is heavily guarded internally, but if
            // it ever throws, degrade to the old minimal pre block — a broken
            // error page is worse than an ugly one.
            try {
                $body = (new DebugPage())->render($e, $request, $status);
            } catch (Throwable) {
                $body = sprintf(
                    "<h1>%d %s</h1><pre>%s\n\n%s</pre>",
                    $status,
                    htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($e::class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($e->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );
            }
        } else {
            // Custom error pages (10.6): hosts may ship errors/{status}.twig
            // or a status-class errors/{4xx|5xx}.twig — that override still
            // wins (BC). When none exists the branded built-in page (14.4)
            // renders from the shared DesignSystem identity instead of a bare
            // <h1>. ErrorPage::render never throws.
            $body = $this->customErrorPage($status, $message, $request)
                ?? \Ions\Http\Ui\ErrorPage::render($status, $message);
        }

        return new Response($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Render a host-provided error template through the shared Twig env
     * (10.6, production HTML path only — debug keeps the DebugPage and the
     * JSON path is untouched).
     *
     * Lookup chain: errors/{status}.twig, then errors/{4xx|5xx}.twig by
     * status class. Context: 'status' (int), 'message' (the SAME client-safe
     * message the minimal page would show — no new information is exposed),
     * 'request_path'.
     *
     * NEVER throws — this runs inside the error renderer, and a broken error
     * page is worse than a plain one (DebugPage::section precedent). Any
     * failure (missing twig config, template syntax error, runtime error) is
     * logged to view.log and answered with null so the built-in page renders.
     */
    private function customErrorPage(int $status, string $message, Request $request): ?string
    {
        try {
            $env = (new \Ions\View\ViewFactory())->make();
            $loader = $env->getLoader();

            $candidates = [
                sprintf('errors/%d.twig', $status),
                sprintf('errors/%dxx.twig', intdiv($status, 100)),
            ];

            foreach ($candidates as $template) {
                if (!$loader->exists($template)) {
                    continue;
                }

                return $env->render($template, [
                    'status' => $status,
                    'message' => $message,
                    'request_path' => $request->getPathInfo(),
                ]);
            }
        } catch (Throwable $e) {
            try {
                \Ions\Bundles\Logs::create('view.log')->warning(sprintf(
                    'Custom error page for status %d failed (%s: %s) — built-in page used.',
                    $status,
                    $e::class,
                    $e->getMessage()
                ));
            } catch (Throwable) {
                // Logging must never mask the fallback.
            }
        }

        return null;
    }
}
