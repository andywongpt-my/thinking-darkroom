<?php

namespace App\Services;

use App\Domain\Culling\PhotoObservation;
use App\Domain\Domain;
use App\Models\AgentConversationMessage;
use App\Models\Project;
use App\Services\Culling\ContextAwareCullingService;
use App\Services\Qa\ConsistencyQaService;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

/**
 * Runs the synchronous, deterministic response path for a human conversation
 * trigger. This service deliberately produces discussion and recommendations;
 * it never changes a photographer's selection or executes a proposal.
 */
class AgentTurnService
{
    /**
     * Private namespace for deterministic UUIDv5 idempotency keys. Generated
     * once per deploy surface; never exposed outside this service.
     */
    private const TURN_IDEMPOTENCY_NAMESPACE = '0f6e2a8e-9c3d-4f7a-9e1b-2c5d4e6f7a8b';

    public function __construct(
        private readonly AgentConversationService $conversation,
        private readonly ContextAwareCullingService $culling,
        private readonly ConsistencyQaService $qa,
        private readonly CreativeRoomService $creative,
        private readonly ToolCallAuditService $audit,
        private readonly Request $request,
    ) {}

    /**
     * Run one project-scoped agent turn.
     *
     * The reply uses the trigger id as its idempotency key, so a browser retry
     * returns the existing reply instead of creating another one. All work is
     * synchronous because the deployment has no queue worker.
     *
     * @return array{message: array<string, mixed>|null, skipped?: string}
     */
    public function run(Project $project, AgentConversationMessage $trigger): array
    {
        if ($trigger->project_id !== $project->id) {
            return $this->skipped('trigger_project_mismatch');
        }

        if ($trigger->author_kind !== AgentConversationMessage::AUTHOR_HUMAN) {
            return $this->skipped('non_human_trigger');
        }

        // The idempotency key must be a valid UUID: the column is a Postgres
        // uuid type and any non-UUID literal makes the dedup lookup fail with
        // SQLSTATE 22P02 before a reply can be persisted. A name-based UUIDv5
        // keeps the key deterministic per trigger, so a browser retry resolves
        // to the same reply on both SQLite (tests) and Neon Postgres (prod).
        $clientMessageId = Uuid::uuid5(
            self::TURN_IDEMPOTENCY_NAMESPACE,
            'agent-turn:'.$project->id.':'.$trigger->id,
        )->toString();
        $existingReply = $project->agentConversationMessages()
            ->where('author_kind', AgentConversationMessage::AUTHOR_AGENT)
            ->where('client_message_id', $clientMessageId)
            ->with('author:id,name')
            ->first();

        if ($existingReply !== null) {
            return [
                'message' => $this->conversation->payloadFor($existingReply),
            ];
        }

        $agent = $project->members()
            ->wherePivot('role', Domain::ROLE_AGENT)
            ->where('users.is_agent', true)
            ->orderBy('users.id')
            ->first();

        if ($agent === null) {
            return $this->skipped('no_agent_member');
        }

        $startedAt = hrtime(true);
        $summary = $this->workspaceSummary($project);

        if ($summary['observations'] === 0 && $summary['photos'] > 0) {
            $this->culling->analyzeProject($project);
        }

        // QA is an ANALYZE-stage persistence operation. It records findings,
        // but does not make or apply a photographer decision.
        $this->qa->review($project, 'all');
        $summary = $this->workspaceSummary($project);
        $reply = $this->composeReply($summary);
        $persisted = $this->conversation->send(
            $project,
            $agent,
            $reply,
            $clientMessageId,
        );
        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

        $this->audit->record(
            $this->request,
            $project,
            $agent,
            'agent_turn',
            Domain::AUTHORITY_ANALYZE,
            ['trigger_id' => $trigger->id],
            [
                'photos' => $summary['photos'],
                'observations' => $summary['observations'],
                'qa_open' => $summary['qa_open'],
                'pending_proposals' => $summary['pending_proposals'],
                'reply_id' => $persisted['message']['id'],
            ],
            Domain::RESULT_COMPLETED,
            $durationMs,
        );

        return [
            'message' => $persisted['message'],
        ];
    }

    /**
     * @return array{photos: int, selected: int, culled: int, unreviewed: int, observations: int, qa_open: int, pending_proposals: int, adopted_brief_title: string|null, has_intent: bool, provenance: string, soft_frames: int}
     */
    private function workspaceSummary(Project $project): array
    {
        $photos = $project->photos()
            ->select(['id', 'selection_state'])
            ->orderBy('id')
            ->get();
        $observations = $this->culling->observationsFor($project);
        $direction = $this->creative->structuredIntentFor($project);
        $context = $this->culling->contextSummary($project);

        return [
            'photos' => $photos->count(),
            'selected' => $photos->where('selection_state', Domain::SELECTION_SELECTED)->count(),
            'culled' => $photos->where('selection_state', Domain::SELECTION_CULLED)->count(),
            'unreviewed' => $photos->where('selection_state', Domain::SELECTION_UNREVIEWED)->count(),
            'observations' => count($observations),
            'qa_open' => $project->findings()->where('status', 'open')->count(),
            'pending_proposals' => $project->proposals()->where('status', Domain::STATE_PENDING_REVIEW)->count(),
            'adopted_brief_title' => is_array($direction)
                ? ($direction['adopted_concept']['title'] ?? null)
                : null,
            'has_intent' => is_array($direction) && ($direction['intent'] ?? null) !== null,
            'provenance' => is_string($context['provider'] ?? null)
                ? $context['provider']
                : 'unknown',
            'soft_frames' => $this->softFrameCount($observations),
        ];
    }

    /**
     * @param  array<int, PhotoObservation>  $observations
     */
    private function softFrameCount(array $observations): int
    {
        return count(array_filter(
            $observations,
            function (PhotoObservation $observation): bool {
                $sharpness = $observation->sharpness();
                if (! is_array($sharpness)) {
                    return false;
                }

                return in_array($sharpness['assessment'] ?? null, ['soft', 'slightly_soft'], true);
            },
        ));
    }

    /**
     * @param  array{photos: int, selected: int, culled: int, unreviewed: int, observations: int, qa_open: int, pending_proposals: int, adopted_brief_title: string|null, has_intent: bool, provenance: string, soft_frames: int}  $summary
     */
    private function composeReply(array $summary): string
    {
        $direction = $summary['adopted_brief_title'] !== null
            ? 'Adopted direction: "'.$summary['adopted_brief_title'].'"; its intent remains the photographer\'s call.'
            : 'No adopted brief is active, so creative fit remains the photographer\'s call.';
        $softFrames = $summary['soft_frames'];
        $softFrameCopy = $softFrames === 0
            ? 'No soft-focus frames were flagged.'
            : "I flagged {$softFrames} soft-focus frame(s) for review.";
        // Only offer a cull proposal when there is something to cull.
        $proposalPrompt = $softFrames > 0
            ? " Want a cull proposal for the {$softFrames} softest frame(s)?"
            : '';

        return "I reviewed the project: {$summary['photos']} photos, {$summary['observations']} observed, "
            ."{$summary['selected']} selected, {$summary['culled']} culled, and {$summary['unreviewed']} unreviewed. "
            ."Analysis provenance: {$summary['provenance']}. "
            ."There are {$summary['qa_open']} open QA finding(s) and {$summary['pending_proposals']} pending proposal(s). "
            ."{$direction} {$softFrameCopy} No final selection changes were made; the photographer decides what to keep, review, or reject."
            .$proposalPrompt;
    }

    /** @return array{message: null, skipped: string} */
    private function skipped(string $reason): array
    {
        return [
            'message' => null,
            'skipped' => $reason,
        ];
    }
}
