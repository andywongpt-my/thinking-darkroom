<?php

namespace App\Services;

use App\Models\AgentToolCall;
use App\Models\Project;

class AgentActivityService
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 100;

    /**
     * Return the project-scoped audit ledger newest-first. Ledger summaries are
     * agent-authored data and remain separate from executable application state.
     *
     * @return array{project_id: int, activity: array<int, array<string, mixed>>, latest_id: int|null, has_older: bool}
     */
    public function forProject(
        Project $project,
        ?int $afterId = null,
        int $limit = self::DEFAULT_LIMIT,
        ?int $beforeId = null,
    ): array {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $base = $project->toolCalls()->with('agent:id,name,is_agent');
        $nextLatestId = null;

        if ($beforeId !== null) {
            $activity = (clone $base)
                ->where('id', '<', $beforeId)
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
            $oldestId = $activity->last()?->id;
            $hasOlder = $oldestId !== null
                && $project->toolCalls()->where('id', '<', $oldestId)->exists();
        } elseif ($afterId !== null) {
            $activity = (clone $base)
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($limit)
                ->get();
            $nextLatestId = $activity->last()?->id;
            $activity = $activity->reverse()->values();
            $hasOlder = false;
        } else {
            $activity = (clone $base)
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
            $oldestId = $activity->last()?->id;
            $hasOlder = $oldestId !== null
                && $project->toolCalls()->where('id', '<', $oldestId)->exists();
        }

        $latestId = $nextLatestId ?? $project->toolCalls()->max('id');

        return [
            'project_id' => $project->id,
            'activity' => $activity
                ->map(fn (AgentToolCall $call): array => $this->payloadFor($call))
                ->values()
                ->all(),
            'latest_id' => $latestId === null ? null : (int) $latestId,
            'has_older' => $hasOlder,
        ];
    }

    /** @return array<string, mixed> */
    public function payloadFor(AgentToolCall $call): array
    {
        $call->loadMissing('agent:id,name,is_agent');

        return [
            'id' => $call->id,
            'agent' => [
                'name' => $call->agent?->name ?? 'Unknown agent',
                'is_agent' => (bool) ($call->agent?->is_agent ?? false),
            ],
            'tool_name' => $call->tool_name,
            'authority' => $call->authority,
            'result_status' => $call->result_status,
            'summary_in' => $call->input,
            'summary_out' => $call->output_summary,
            'duration_ms' => $call->duration_ms,
            'created_at' => $call->created_at?->toISOString(),
        ];
    }
}
