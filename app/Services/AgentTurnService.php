<?php

namespace App\Services;

use App\Domain\Culling\PhotoObservation;
use App\Domain\Domain;
use App\Models\AgentConversationMessage;
use App\Models\Project;
use App\Models\User;
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
        private readonly ProposalService $proposalService,
        private readonly AgentLlmService $llm,
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
            $this->culling->analyzeProject($project, $trigger->user_id !== null ? User::find($trigger->user_id) : null);
        }

        // QA is an ANALYZE-stage persistence operation. It records findings,
        // but does not make or apply a photographer decision.
        $this->qa->review($project, 'all');
        $summary = $this->workspaceSummary($project);

        // Demo-chain upgrade: when the trigger message asks for keepers or a
        // cull, the turn escalates from statistics to the real tool chain —
        // per-photo recommendations and (for explicit cull intents) a cull
        // PROPOSAL the photographer still has to approve. Authority stays
        // intact: the turn only ever ANALYZEs and PROPOSEs.
        $intent = $this->detectIntent((string) $trigger->body);
        // P2c — the trigger's human author brings their own BYO key when one
        // is stored; agent accounts and guests fall back to deployment env.
        $actingUser = $trigger->user_id !== null ? User::find($trigger->user_id) : null;
        $actingUser = ($actingUser !== null && ! $actingUser->isAgent()) ? $actingUser : null;
        $proposalId = null;
        if ($intent['keepers'] || $intent['cull']) {
            $recommendations = $this->culling->recommendForProject($project);

            if ($intent['cull'] && $summary['photos'] > 0) {
                $proposalId = $this->createCullProposalFromRecommendations(
                    $project,
                    $agent,
                    $recommendations,
                    $intent['direction_query'],
                );
            }

            // LLM reasoning first: grounded in the same persisted evidence,
            // able to answer "why" and compare frames across the set. The
            // deterministic composer stays as the contract-preserving
            // fallback when the LLM is disabled or unreachable.
            $reply = $this->llm->reply($project, (string) $trigger->body, $summary, $intent['direction_query'], $actingUser);

            if ($reply === null) {
                $reply = $this->composeKeeperReply($summary, $recommendations, $intent, $proposalId);
            } elseif ($proposalId !== null) {
                $reply .= "\nA cull proposal (#".$proposalId.') is waiting for your approval in the Proposals panel.';
            }
        } else {
            $reply = $this->llm->reply($project, (string) $trigger->body, $summary, $intent['direction_query'], $actingUser)
                ?? $this->composeReply($summary);
        }

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
                'turn_intent' => $intent['cull'] ? 'cull_proposal' : ($intent['keepers'] ? 'keepers' : 'status'),
                'cull_proposal_id' => $proposalId,
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

    private const KEEPER_PATTERNS = [
        'best', 'keeper', 'keepers', 'top', 'strongest', 'recommend', 'recommendation',
        'which photos', 'which frames', 'which one', 'which ones', 'favorites',
        'favourites', 'stand out', 'lead the set', 'hero',
    ];

    private const CULL_PATTERNS = [
        'propose a cull', 'propose cull', 'cull proposal', 'draft a cull',
        'prepare a cull', 'cull the', 'cull them', 'cull it',
        'reject candidates', 'cull the weak', 'clean up the set',
    ];

    /**
     * Detect the demo-chain intent in a trigger message. Returns which
     * escalation the turn should take and the quoted direction (if any).
     *
     * @return array{keepers: bool, cull: bool, direction_query: string|null}
     */
    private function detectIntent(string $body): array
    {
        $text = mb_strtolower($body);
        $cull = false;
        foreach (self::CULL_PATTERNS as $needle) {
            if (str_contains($text, $needle)) {
                $cull = true;
                break;
            }
        }

        $keepers = false;
        if (! $cull) {
            foreach (self::KEEPER_PATTERNS as $needle) {
                if (str_contains($text, $needle)) {
                    $keepers = true;
                    break;
                }
            }
        }

        return [
            'keepers' => $keepers,
            'cull' => $cull,
            'direction_query' => $this->directionQuery($body),
        ];
    }

    /**
     * Extract a quoted creative-direction fragment from the trigger, e.g.
     * 'find the 3 best keepers under "Documentary Intimacy"'.
     */
    private function directionQuery(string $body): ?string
    {
        if (preg_match('/"([^"]{2,120})"/u', $body, $m) === 1) {
            return $m[1];
        }
        if (preg_match("/'([^']{2,120})'/u", $body, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Persist a cull PROPOSAL from the strongest reject candidates. Mirrors
     * the propose_cull tool path (PROPOSE authority, proposal-only). Items
     * carry the recommendation evidence so the approve UI shows WHY.
     *
     * @param  array<string, mixed>  $recommendations  recommendForProject() payload
     * @return int|null proposal id, or null when there is nothing to cull
     */
    private function createCullProposalFromRecommendations(
        Project $project,
        User $agent,
        array $recommendations,
        ?string $directionQuery,
    ): ?int {
        /** @var list<array<string, mixed>> $rejects */
        $rejects = array_values(array_filter(
            (array) ($recommendations['recommendations'] ?? []),
            fn (array $r) => ($r['recommendation'] ?? null) === Domain::CULL_RECOMMEND_REJECT_CANDIDATE
                && ($r['photo']['selection_state'] ?? null) !== Domain::SELECTION_CULLED,
        ));

        if ($rejects === []) {
            return null;
        }

        // Cull the weakest first (lowest confidence wins the top slot).
        usort($rejects, fn (array $a, array $b) => (float) ($a['confidence'] ?? 0) <=> (float) ($b['confidence'] ?? 0));

        $items = [];
        foreach ($rejects as $reject) {
            $photo = (array) $reject['photo'];
            $items[] = [
                'photo_id' => (int) $photo['id'],
                'kind' => 'selection',
                'action' => 'cull',
                'rationale' => trim((string) ($reject['technical_rationale'] ?? '')),
                'params' => [
                    'context_aware' => true,
                    'recommendation' => $reject['recommendation'] ?? null,
                    'confidence' => $reject['confidence'] ?? null,
                    'technical_rationale' => $reject['technical_rationale'] ?? null,
                    'creative_rationale' => $reject['creative_rationale'] ?? null,
                    'tradeoff' => $reject['tradeoff'] ?? null,
                    'influenced_by' => $reject['influenced_by'] ?? [],
                    'similarity_group' => $reject['similarity_group'] ?? null,
                ],
            ];
        }

        $direction = $directionQuery !== null ? ' under "'.$directionQuery.'"' : '';
        $proposal = $this->proposalService->createProposal(
            $project,
            $agent,
            Domain::TYPE_CULL,
            $items,
            'Agent turn: cull '.count($items).' weak frame(s)'.$direction.' — awaiting your approval.',
            ['created_via' => 'agent_turn', 'tool' => 'propose_cull'],
        );

        $this->audit->record(
            $this->request,
            $project,
            $agent,
            'propose_cull',
            Domain::AUTHORITY_PROPOSE,
            ['created_via' => 'agent_turn', 'items' => count($items)],
            ['proposal_id' => $proposal->id, 'type' => $proposal->type, 'status' => $proposal->status],
        );

        return $proposal->id;
    }

    /**
     * Reply for keeper/cull intents: a per-photo list with rationale, ending
     * with the photographer-authority reminder and (when created) a pointer
     * to the pending proposal.
     *
     * @param  array{photos: int, selected: int, culled: int, unreviewed: int, observations: int, qa_open: int, pending_proposals: int, adopted_brief_title: string|null, has_intent: bool, provenance: string, soft_frames: int}  $summary
     * @param  array<string, mixed>  $recommendations
     * @param  array{keepers: bool, cull: bool, direction_query: string|null}  $intent
     */
    private function composeKeeperReply(array $summary, array $recommendations, array $intent, ?int $proposalId): string
    {
        /** @var list<array<string, mixed>> $recs */
        $recs = (array) ($recommendations['recommendations'] ?? []);
        $keepers = array_values(array_filter($recs, fn (array $r) => in_array(
            $r['recommendation'] ?? null,
            [Domain::CULL_RECOMMEND_KEEP, Domain::CULL_RECOMMEND_STRONG_KEEP],
            true,
        )));
        usort($keepers, fn (array $a, array $b) => (float) ($b['confidence'] ?? 0) <=> (float) ($a['confidence'] ?? 0));

        $direction = $summary['adopted_brief_title'] !== null
            ? 'Adopted direction: "'.$summary['adopted_brief_title'].'".'
            : 'No adopted brief is active, so these are technical-only reads.';
        if ($intent['direction_query'] !== null) {
            $direction .= ' Filtering against your note: "'.$intent['direction_query'].'".';
        }

        $lines = [];
        $lines[] = $direction;
        if ($keepers === []) {
            $lines[] = 'No clear keepers yet: '.$summary['photos'].' photo(s) observed, '
                .$summary['observations'].' with persisted analysis. Run analyze_project_photos for full coverage.'
                .' No final selection changes were made; the photographer decides what to keep, review, or reject.';
        } else {
            $lines[] = 'Here are my top '.count($keepers).' keeper candidate(s):';
            $rank = 0;
            foreach (array_slice($keepers, 0, 5) as $keeper) {
                $rank++;
                $photo = (array) $keeper['photo'];
                $lines[] = sprintf(
                    '%d. %s — %s (%d%% confidence): %s',
                    $rank,
                    (string) ($photo['original_name'] ?? $photo['filename'] ?? ('photo '.$photo['id'])),
                    str_replace('_', ' ', (string) $keeper['recommendation']),
                    (int) round(100 * (float) ($keeper['confidence'] ?? 0)),
                    trim((string) ($keeper['technical_rationale'] ?? $keeper['creative_rationale'] ?? '')),
                );
            }
            $lines[] = 'No final selection changes were made; the photographer decides what to keep, review, or reject.';
        }

        if ($proposalId !== null) {
            $lines[] = 'A cull proposal (#'.$proposalId.') is waiting for your approval in the Proposals panel.';
        } elseif ($intent['cull']) {
            $lines[] = 'No reject candidates to cull right now — the set looks technically clean.';
        }

        return implode("\n", $lines);
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
