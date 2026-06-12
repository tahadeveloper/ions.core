<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Task;
use Ions\Support\Http;

/**
 * Outbound HTTP via the framework Http client (8.5). Two thin examples:
 *
 * - avatarUrl()    — derives a Gravatar URL from an email and HEAD/GET-confirms
 *                    it resolves (so a caller can fall back to a default).
 * - notifyTaskDone() — POSTs a completion webhook when a task transitions to
 *                    "done", letting an external system react.
 *
 * Both go through Ions\Support\Http, so Http::fake() intercepts them in tests
 * (the suite asserts the webhook fired without making a real network call).
 */
final class AvatarFetcher
{
    /** Where the task-completion webhook is delivered (a fixed example endpoint). */
    private const WEBHOOK_URL = 'https://hooks.taskflow.test/task-completed';

    /**
     * Resolve a Gravatar URL for an email and confirm it loads. Returns the URL
     * on a 200, or null on any non-200 / transport error (caller falls back).
     */
    public function avatarUrl(string $email, int $size = 80): ?string
    {
        $hash = md5(strtolower(trim($email)));
        $url = "https://www.gravatar.com/avatar/{$hash}?s={$size}&d=identicon";

        try {
            $response = Http::timeout(5)->get($url);
        } catch (\Throwable) {
            return null;
        }

        return $response->ok() ? $url : null;
    }

    /**
     * POST a completion webhook for a freshly-done task. Returns true when the
     * endpoint accepts it (2xx). Swallows transport errors (returns false) so a
     * webhook outage never blocks marking a task done.
     */
    public function notifyTaskDone(Task $task): bool
    {
        try {
            $response = Http::timeout(5)->post(self::WEBHOOK_URL, [
                'event' => 'task.completed',
                'task_id' => $task->getKey(),
                'title' => (string) $task->title,
                'project_id' => (int) $task->project_id,
            ]);
        } catch (\Throwable) {
            return false;
        }

        return $response->ok();
    }
}
