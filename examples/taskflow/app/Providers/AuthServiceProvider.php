<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use Ions\Auth\Contracts\Authenticatable;
use Ions\Auth\Gate;
use Ions\Container\ServiceProvider;

/**
 * Auto-discovered (app/Providers/*) — wires the authorization gate: maps the
 * domain models to their policies and defines a couple of plain abilities the
 * controllers/views use. The framework's AuthProvider binds the singleton
 * 'gate'; this accumulates definitions on that one instance in boot().
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Nothing to bind — definitions happen in boot() once 'gate' exists.
    }

    public function boot(): void
    {
        /** @var Gate $gate */
        $gate = $this->container->get('gate');

        $gate->policy(Project::class, ProjectPolicy::class);
        $gate->policy(Task::class, TaskPolicy::class);

        // A simple, user-only ability: only verified users may create projects.
        $gate->define('create-project', static function (?Authenticatable $user): bool {
            return $user instanceof User && $user->hasVerifiedEmail();
        });
    }
}
