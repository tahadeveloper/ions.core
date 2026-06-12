<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Ions\Foundation\BaseController;
use Ions\Http\RedirectResponse;
use Ions\Support\Request;

/**
 * Owner-facing sharing actions (13.6), behind ['web.auth', 'verified'].
 *
 * - share()       — mints a SIGNED, EXPIRING link (signedRoute) to the public
 *                   read-only board for a project the caller owns, and flashes
 *                   it back to the project page. The framework's UrlSigner
 *                   covers path+query with an HMAC over APP_KEY and stamps an
 *                   `expires` epoch; the 'signed' middleware on share.board then
 *                   rejects any tampered or expired link with a 403.
 * - unsubscribe() — a SIGNED one-click opt-out: flips the user's
 *                   notifications_opt_out flag. The link is guarded by 'signed'
 *                   (see routes/web.php), so a tampered link 403s before this
 *                   action ever runs.
 *
 * The PUBLIC board itself lives in PublicBoardController (no auth, no session,
 * so it is cacheable) — this controller only generates/consumes the links.
 */
final class ShareController extends BaseController
{
    /** Share links live for 7 days. */
    private const SHARE_TTL_DAYS = 7;

    public function share(Request $request, Project $project): RedirectResponse
    {
        // Owner-only (ProjectPolicy::delete is the owner-only ability).
        $this->authorize('delete', $project);

        $expiresAt = (new \DateTimeImmutable())->modify('+' . self::SHARE_TTL_DAYS . ' days');

        $url = signedRoute('share.board', ['project' => $project->getKey()], $expiresAt);

        return redirect('/projects/' . $project->getKey())
            ->with('shareUrl', $url)
            ->with('status', 'Share link created.');
    }

    /**
     * Build a signed unsubscribe link for a user (used by digests/emails). Kept
     * here so the link-generation and the opt-out action sit together; an email
     * template would call this to embed a one-click unsubscribe URL.
     */
    public static function unsubscribeUrl(User $user): string
    {
        return signedRoute('share.unsubscribe', ['user' => $user->getKey()]);
    }

    /**
     * One-click unsubscribe target. Reaching this action means the 'signed'
     * middleware already verified the HMAC, so we can trust the {user} id and
     * flip the opt-out flag. No auth needed — the signature IS the authorization.
     */
    public function unsubscribe(Request $request, User $user): RedirectResponse
    {
        $user->forceFill(['notifications_opt_out' => true])->save();

        return redirect('/')->with('status', 'You have been unsubscribed from notifications.');
    }
}
