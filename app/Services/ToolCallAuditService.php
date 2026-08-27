<?php

namespace App\Services;

use App\Domain\Domain;
use App\Models\AgentToolCall;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Records an immutable audit trail of every WebMCP agent tool invocation.
 *
 * The same trail is used for the "Agent Activity" panel, so the UI's record
 * of what the agent did is exactly what the server persisted.
 */
class ToolCallAuditService
{
    /**
     * Record a completed (or denied / failed) agent tool call.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $outputSummary
     */
    public function record(
        Request $request,
        Project $project,
        User $actor,
        string $toolName,
        string $authority,
        array $input,
        array $outputSummary,
        string $resultStatus = Domain::RESULT_COMPLETED,
        ?float $durationMs = null,
    ): AgentToolCall {
        try {
            return AgentToolCall::create([
                'project_id' => $project->id,
                'agent_id' => $actor->id,
                'tool_name' => $toolName,
                'authority' => $authority,
                'http_method' => $request->method(),
                'path' => $request->path(),
                'result_status' => $resultStatus,
                'input' => $input,
                'output_summary' => $outputSummary,
                'duration_ms' => $durationMs === null ? null : (int) round($durationMs),
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ]);
        } catch (\Throwable $e) {
            // The audit trail must never take down a tool invocation.
            Log::warning('webmcp audit write failed', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);

            return new AgentToolCall;
        }
    }
}
