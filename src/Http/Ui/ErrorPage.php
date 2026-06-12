<?php

declare(strict_types=1);

namespace Ions\Http\Ui;

/**
 * The branded built-in production error page (Phase 14.4).
 *
 * Served by {@see \Ions\Http\ExceptionHandler::html()} in production
 * (APP_DEBUG off) when the host ships no `errors/{status}.twig` /
 * `errors/{4xx|5xx}.twig` override — replacing the pre-14.4 bare
 * `<h1>%d %s</h1>` fallback with one consistent visual identity drawn from
 * {@see DesignSystem}.
 *
 * Hard constraints (mirrored from the spec): JS-FREE (no `<script>` at all),
 * inline-CSS only (no `<link>`, no external refs), tiny and self-contained — an
 * error page must never depend on the asset pipeline that may itself be the
 * cause of the error. Everything is HTML-escaped.
 *
 * This runs INSIDE exception handling: {@see render()} MUST NEVER throw. An
 * unknown status falls back to a generic "Error" title; an empty message still
 * renders a complete document.
 */
final class ErrorPage
{
    /**
     * Human titles for the documented statuses. Unknown codes fall back to the
     * generic "Error" so the renderer never throws on an unexpected status.
     *
     * @var array<int, string>
     */
    private const TITLES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        419 => 'Page Expired',
        429 => 'Too Many Requests',
        500 => 'Server Error',
        503 => 'Service Unavailable',
    ];

    /**
     * Render a complete, self-contained, JS-free HTML error document: the big
     * status number, the human title for the status, the client-safe message
     * and a "back to home" link to `/`. All dynamic values are HTML-escaped and
     * the stylesheet is inlined from {@see DesignSystem}.
     */
    public static function render(int $status, string $message): string
    {
        $title = self::TITLES[$status] ?? 'Error';
        $message = $message !== '' ? $message : $title;

        $statusHtml = self::e((string) $status);
        $titleHtml = self::e($title);
        $messageHtml = self::e($message);
        $css = DesignSystem::tokensOnly();

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$statusHtml} — {$titleHtml}</title>
            <style>
            {$css}
            *{box-sizing:border-box}
            body{
              margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
              background:var(--ion-bg);color:var(--ion-text);
              font-family:var(--ion-font-sans);line-height:1.5;
            }
            main{text-align:center;padding:var(--ion-space-6);max-width:32rem}
            .ion-err-status{
              font-size:6rem;font-weight:800;line-height:1;margin:0;
              color:var(--ion-accent);letter-spacing:-.04em;
            }
            .ion-err-title{
              font-size:1.5rem;font-weight:600;margin:var(--ion-space-3) 0 0;
              color:var(--ion-text);
            }
            .ion-err-message{margin:var(--ion-space-2) 0 var(--ion-space-6);color:var(--ion-muted)}
            .ion-err-home{
              display:inline-block;color:var(--ion-accent);text-decoration:none;
              font-weight:600;padding:var(--ion-space-2) var(--ion-space-4);
              border:1px solid var(--ion-border);border-radius:6px;
            }
            .ion-err-home:hover{border-color:var(--ion-accent)}
            </style>
            </head>
            <body>
            <main>
            <p class="ion-err-status">{$statusHtml}</p>
            <h1 class="ion-err-title">{$titleHtml}</h1>
            <p class="ion-err-message">{$messageHtml}</p>
            <p><a class="ion-err-home" href="/">Back to the homepage</a></p>
            </main>
            </body>
            </html>
            HTML;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
