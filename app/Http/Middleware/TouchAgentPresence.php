<?php

namespace App\Http\Middleware;

use App\Domain\Domain;
use App\Models\AgentPresence;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * WebMCP tool-call presence touch.
 *
 * A machine agent calling any WebMCP tool endpoint IS the agent working in
 * that project — that is honest liveness, same as the explicit heartbeat.
 * Tool calls therefore refresh `agent_presences`, so the dashboard and the
 * workspace presence strip show the agent online while it works, without
 * requiring the agent to poll the heartbeat endpoint.
 *
 * Humans never write presence here: presence means machine-agent liveness.
 * The heartbeat endpoint is skipped because its controller already writes
 * the row (and its policy is the authoritative liveness gate).
 */
class TouchAgentPresence
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        $project = $request->route('project');

        if (
            ! $user instanceof User
            || ! $user->isAgent()
            || ! $project instanceof Project
            || str_starts_with((string) ($request->route()?->getName() ?? ''), 'api.presence.')
        ) {
            return $response;
        }

        $role = $project->members()
            ->where('project_members.user_id', $user->id)
            ->value('project_members.role');

        if ($role !== Domain::ROLE_AGENT) {
            return $response;
        }

        try {
            AgentPresence::query()->upsert(
                values: [[
                    'project_id' => $project->id,
                    'user_id' => $user->id,
                    'last_seen_at' => CarbonImmutable::now(),
                ]],
                uniqueBy: ['project_id', 'user_id'],
                update: ['last_seen_at'],
            );
        } catch (Throwable) {
            // Liveness is best-effort: a presence write failure must never
            // break the tool response the agent already received.
        }

        return $response;
    }
}
