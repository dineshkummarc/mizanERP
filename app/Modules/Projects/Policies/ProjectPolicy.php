<?php

namespace App\Modules\Projects\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ProjectView');
    }

    public function view(User $user, Project $project): bool
    {
        return $user->hasPermissionTo('ProjectView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ProjectCreate');
    }

    public function update(User $user, Project $project): bool
    {
        return $user->hasPermissionTo('ProjectUpdate');
    }

    public function delete(User $user, Project $project): bool
    {
        if (! $user->hasPermissionTo('ProjectDelete')) {
            return false;
        }

        // Projects with assignment history must not be deleted — end the
        // assignments (or the project) instead.
        return ! $project->employees()->exists();
    }

    /** Firing an on-demand check makes the server issue an outbound request. */
    public function runHealthCheck(User $user, Project $project): bool
    {
        return $user->hasPermissionTo('ProjectHealthCheck');
    }
}
