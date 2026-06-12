<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Ions\Foundation\BaseController;
use Ions\Foundation\Kernel;
use Ions\Http\RedirectResponse;
use Ions\Support\Request;
use Ions\View\View;

/**
 * In-app notification centre (13.5): lists the logged-in user's
 * database-channel notification rows (newest first) and lets them mark one
 * read. Rows are written by the DatabaseChannel — see App\Notifications\*
 * toDatabase(). The `data` column is json-decoded for display.
 */
final class NotificationController extends BaseController
{
    protected string $viewPath = 'notifications';

    public function index(Request $request): View
    {
        $user = $this->currentUser($request);

        $rows = $this->table()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', (string) $user->getKey())
            ->orderByDesc('created_at')
            ->get()
            ->map(static function (object $row): array {
                /** @var array<string, mixed> $data */
                $data = json_decode((string) $row->data, true) ?: [];

                return [
                    'id' => $row->id,
                    'type' => $row->type,
                    'message' => $data['message'] ?? '',
                    'read' => $row->read_at !== null,
                    'created_at' => $row->created_at,
                ];
            })
            ->all();

        return $this->view('index', [
            'notifications' => $rows,
            'status' => flash('status'),
        ]);
    }

    public function markRead(Request $request, string $id): RedirectResponse
    {
        $user = $this->currentUser($request);

        $this->table()
            ->where('id', $id)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', (string) $user->getKey())
            ->update(['read_at' => date('Y-m-d H:i:s')]);

        return redirect('/notifications')->with('status', 'Notification marked read.');
    }

    private function currentUser(Request $request): User
    {
        $user = $request->attributes->get('auth_user');

        if (!$user instanceof User) {
            abort(403, 'Not authenticated.');
        }

        return $user;
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return Kernel::app()->get('db')->connection()
            ->table((string) config('notifications.table', 'notifications'));
    }
}
