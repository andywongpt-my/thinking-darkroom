<?php

namespace App\Policies;

use App\Domain\Domain;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Any user who is a member (in project_members) may view a workspace.
     * The owner is always a member (seeded that way).
     */
    public function view(User $user, Project $project): bool
    {
        return $this->roleFor($user, $project) !== null;
    }

    /** Agents, owners, and photographers can persist non-final proposals. */
    public function propose(User $user, Project $project): bool
    {
        return in_array($this->roleFor($user, $project), [
            Domain::ROLE_OWNER,
            Domain::ROLE_PHOTOGRAPHER,
            Domain::ROLE_AGENT,
        ], true);
    }

    /** Agents, owners, and photographers can persist non-final analysis. */
    public function analyze(User $user, Project $project): bool
    {
        return $this->propose($user, $project);
    }

    /** Only a machine agent account with an agent project role may heartbeat. */
    public function heartbeat(User $user, Project $project): bool
    {
        return $user->isAgent() && $this->roleFor($user, $project) === Domain::ROLE_AGENT;
    }

    /** Originals may only be uploaded by a human owner or photographer. */
    public function upload(User $user, Project $project): bool
    {
        return ! $user->isAgent() && in_array($this->roleFor($user, $project), [
            Domain::ROLE_OWNER,
            Domain::ROLE_PHOTOGRAPHER,
        ], true);
    }

    private function roleFor(User $user, Project $project): ?string
    {
        $role = $project->members()
            ->where('project_members.user_id', $user->id)
            ->value('project_members.role');

        return is_string($role) ? $role : null;
    }
}
