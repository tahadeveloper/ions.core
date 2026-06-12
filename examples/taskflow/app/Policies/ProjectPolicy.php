<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Authorization for projects. A user may view/update a project they own or are
 * a member of; only the owner may delete it. Registered against
 * {@see Project} in {@see \App\Providers\AuthServiceProvider}, so the Gate
 * resolves these methods for $this->authorize('view', $project) etc.
 */
final class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $this->ownerOrMember($user, $project);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->ownerOrMember($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->isOwner($user, $project);
    }

    private function ownerOrMember(User $user, Project $project): bool
    {
        return $this->isOwner($user, $project) || $this->isMember($user, $project);
    }

    private function isOwner(User $user, Project $project): bool
    {
        return (int) $project->owner_id === (int) $user->getKey();
    }

    private function isMember(User $user, Project $project): bool
    {
        return $project->members()
            ->where('users.id', $user->getKey())
            ->exists();
    }
}
