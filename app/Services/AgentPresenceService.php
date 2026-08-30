<?php

namespace App\Services;

use App\Domain\Domain;
use App\Models\AgentPresence;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;

class AgentPresenceService
{
    public const ONLINE_TTL_SECONDS = 90;

    /**
     * Refresh the authenticated agent's presence and return the project state.
     *
     * Authorization is deliberately handled by the controller policy before
     * this method is called.
     *
     * @return array{project_id: int, online: bool, agents: array<int, array{id: int, name: string, status: string, last_seen_at: string|null}>, checked_at: string}
     */
    public function heartbeat(Project $project, User $user): array
    {
        AgentPresence::query()->upsert(
            values: [[
                'project_id' => $project->id,
                'user_id' => $user->id,
                'last_seen_at' => CarbonImmutable::now(),
            ]],
            uniqueBy: ['project_id', 'user_id'],
            update: ['last_seen_at'],
        );

        return $this->forProject($project);
    }

    /**
     * Return all current, eligible agent members. A member with no presence
     * row has never checked in, which is a truthful offline state.
     *
     * @return array{project_id: int, online: bool, agents: array<int, array{id: int, name: string, status: string, last_seen_at: string|null}>, checked_at: string}
     */
    public function forProject(Project $project): array
    {
        $checkedAt = CarbonImmutable::now();
        $cutoff = $checkedAt->subSeconds(self::ONLINE_TTL_SECONDS);

        $agents = $project->members()
            ->where('users.is_agent', true)
            ->wherePivot('role', Domain::ROLE_AGENT)
            ->with([
                'agentPresences' => fn ($query) => $query->where('project_id', $project->id),
            ])
            ->orderBy('users.id')
            ->get()
            ->map(function (User $agent) use ($cutoff): array {
                $lastSeenAt = $agent->agentPresences->first()?->last_seen_at;

                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'status' => $lastSeenAt !== null && $lastSeenAt->isAfter($cutoff) ? 'online' : 'offline',
                    'last_seen_at' => $lastSeenAt?->toISOString(),
                ];
            })
            ->values()
            ->all();

        return [
            'project_id' => $project->id,
            'online' => collect($agents)->contains(fn (array $agent): bool => $agent['status'] === 'online'),
            'agents' => $agents,
            'checked_at' => $checkedAt->toISOString(),
        ];
    }
}
