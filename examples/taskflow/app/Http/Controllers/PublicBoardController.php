<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use Ions\Foundation\RegisterDB;
use Ions\Support\Request;
use Ions\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The PUBLIC, read-only shared board (13.6).
 *
 * Deliberately a PLAIN controller (NOT a BaseController) so it wires up no
 * auth/CSRF machinery — the board is anonymous and read-only. It renders
 * through the framework's normal View layer: the action returns a View and
 * the dispatcher's ResponseNormalizer renders it.
 *
 * WHY this stays cacheable through the framework view layer:
 *   The board template prints NO CSRF token and contains NO form. Since core
 *   4.6 the ViewFactory's `_csrf_token` Twig global is a LAZY Stringable
 *   (CsrfTokenProxy) — it only generates + persists the `_csrf/web` session
 *   entry when a template actually OUTPUTS `{{ _csrf_token }}`. A form-less
 *   page like this one therefore never writes the session, so the request
 *   stays anonymous and ResponseCache::shouldCache() (12.5) caches it.
 *   (Before 4.6 the global was seeded eagerly on every render, which forced
 *   this controller to bypass the view layer with a raw Twig render — the
 *   13.6 dogfood finding that drove the core fix. See README "Sharing &
 *   Caching".)
 *
 * With this in place the route ['signed', 'cache.response'] behaves as designed:
 *   - 'signed' verifies the signedRoute() HMAC + expiry (tampered/expired → 403);
 *   - 'cache.response' caches the anonymous 200 (2nd hit → X-Ions-Cache: HIT).
 *
 * The encrypted note is intentionally NOT shown here — the public board lists
 * only the project name and its tasks (title + status).
 */
final class PublicBoardController
{
    public function board(Request $request, int $project): View
    {
        // Plain controller: BaseController would boot the DB in its constructor;
        // we are not one, so do it explicitly.
        RegisterDB::boot();

        $model = Project::query()->find($project);
        if ($model === null) {
            throw new NotFoundHttpException('No such project.');
        }

        return new View('share/board.twig', [
            'project' => $model,
            'tasks' => $model->tasks()->orderByDesc('id')->get(),
        ]);
    }
}
