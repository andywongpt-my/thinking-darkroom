<?php

namespace App\Services;

use App\Domain\Domain;
use App\Models\BrainstormSession;
use App\Models\CreativeBrief;
use App\Models\CreativeConcept;
use App\Models\CreativeConceptItem;
use App\Models\PhotographerDecision;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns every state transition in the Creative Room and enforces the
 * creative-authority boundary.
 *
 * Sprint 1 authority model preserved:
 *  - READ      → agent may inspect anything (controllers/catalog).
 *  - PROPOSE   → agent creates concepts and creative-brief proposals.
 *  - EXECUTE   → only approved execution capabilities (Sprint 1 proposals).
 *  - HUMAN     → adopting a creative direction is EXCLUSIVELY a photographer
 *                action. No method here can be reached by an agent; the WebMCP
 *                tool catalog deliberately contains no adoption tool.
 *
 * The future Sprint 3 culling contract is:
 *   CreativeRoomService::structuredIntentFor($project)
 * returns the adopted concept → derived brief, answering
 * "what does this photographer value for this project?" in a machine-readable
 * shape that a future culling/retouching agent can consume.
 */
class CreativeRoomService
{
    /* ------------------------------------------------------------------ */
    /*  PROPOSE (agent or photographer may create concepts)                */
    /* ------------------------------------------------------------------ */

    /**
     * Create a brainstorm session from freeform photographer thinking.
     * This is the source context the agent reasons from. HUMAN-framed input,
     * but creation itself is a plain data write (the photographer owns the
     * words; no creative direction is adopted here).
     */
    public function openBrainstorm(
        Project $project,
        User $creator,
        string $input,
        string $status = 'open',
    ): BrainstormSession {
        $input = trim($input);
        if ($input === '') {
            throw ValidationException::withMessages([
                'input' => 'Brainstorm input cannot be empty.',
            ]);
        }

        return BrainstormSession::create([
            'project_id' => $project->id,
            'photographer_id' => $creator->id,
            'input' => $input,
            'status' => $status,
        ]);
    }

    /**
     * Create a proposed creative concept with structured dimensions.
     *
     * This is strictly PROPOSE: the concept is created in `proposed` status
     * and NEVER adopts the creative direction.
     *
     * @param  array<string, mixed>  $content  structured dimensions (extensible)
     * @param  array<int, array<string, mixed>>|null  $items  discrete readable traits
     */
    public function proposeConcept(
        Project $project,
        User $creator,
        ?BrainstormSession $session,
        string $title,
        ?string $summary,
        array $content,
        ?array $items = null,
    ): CreativeConcept {
        $title = trim($title);
        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'Concept title is required.']);
        }

        return DB::transaction(function () use (
            $project, $creator, $session, $title, $summary, $content, $items,
        ) {
            $concept = CreativeConcept::create([
                'project_id' => $project->id,
                'brainstorm_session_id' => $session?->id,
                'title' => $title,
                'summary' => $summary ? trim($summary) : null,
                'content' => $content,
                'status' => Domain::CONCEPT_STATUS_PROPOSED,
                'created_by' => $creator->id,
            ]);

            foreach ($items ?? [] as $item) {
                CreativeConceptItem::create([
                    'creative_concept_id' => $concept->id,
                    'dimension' => $item['dimension'] ?? 'note',
                    'label' => $item['label'] ?? null,
                    'value' => $item['value'] ?? null,
                    'source' => $item['source'] ?? ($creator->isAgent() ? 'agent' : 'photographer'),
                ]);
            }

            return $concept->load('items');
        });
    }

    /**
     * PROPOSE: create an exploring child/revision of an existing concept.
     * The parent stays untouched; lineage is preserved via parent_concept_id.
     */
    public function proposeConceptRevision(
        Project $project,
        User $creator,
        CreativeConcept $source,
        string $title,
        ?string $summary,
        array $content,
        ?array $items = null,
    ): CreativeConcept {
        $this->assertSameProject($source, $project);

        $child = $this->proposeConcept(
            $project,
            $creator,
            $source->brainstormSession,
            $title,
            $summary,
            $content,
            $items,
        );

        $child->forceFill(['parent_concept_id' => $source->id])->save();

        return $child->load('items');
    }

    /**
     * PROPOSE: combine structured ideas from two or more concepts into a new
     * proposed concept with visible lineage (lineage_basis).
     *
     * @param  array<int, array{concept_id: int, note?: string}>  $sources
     */
    public function proposeConceptMerge(
        Project $project,
        User $creator,
        array $sources,
        string $title,
        ?string $summary,
        array $content,
        ?array $items = null,
    ): CreativeConcept {
        if (count($sources) < 2) {
            throw ValidationException::withMessages([
                'sources' => 'A merge requires at least two source concepts.',
            ]);
        }

        $sourceIds = array_column($sources, 'concept_id');
        $unique = array_values(array_unique($sourceIds));
        if (count($unique) !== count($sourceIds)) {
            throw ValidationException::withMessages([
                'sources' => 'Duplicate source concepts are not allowed in a merge.',
            ]);
        }

        $concepts = $project->creativeConcepts()
            ->whereIn('id', $sourceIds)
            ->get()
            ->keyBy('id');

        foreach ($sourceIds as $id) {
            if (! $concepts->has((int) $id)) {
                throw ValidationException::withMessages([
                    'sources' => "Concept [{$id}] does not belong to this project.",
                ]);
            }
        }

        $lineageBasis = collect($sources)->map(function ($source) use ($concepts) {
            $concept = $concepts->get((int) $source['concept_id']);

            return [
                'concept_id' => (int) $source['concept_id'],
                'title' => $concept->title,
                'note' => $source['note'] ?? null,
            ];
        })->values()->all();

        return DB::transaction(function () use (
            $project, $creator, $concepts, $lineageBasis, $title, $summary, $content, $items,
        ) {
            $merged = CreativeConcept::create([
                'project_id' => $project->id,
                'brainstorm_session_id' => $concepts->first()->brainstorm_session_id,
                'title' => $title,
                'summary' => $summary ? trim($summary) : null,
                'content' => $content,
                'status' => Domain::CONCEPT_STATUS_MERGED,
                'created_by' => $creator->id,
                'lineage_basis' => $lineageBasis,
            ]);

            foreach ($items ?? [] as $item) {
                CreativeConceptItem::create([
                    'creative_concept_id' => $merged->id,
                    'dimension' => $item['dimension'] ?? 'note',
                    'label' => $item['label'] ?? null,
                    'value' => $item['value'] ?? null,
                    'source' => $item['source'] ?? ($creator->isAgent() ? 'agent' : 'photographer'),
                ]);
            }

            return $merged->load('items');
        });
    }

    /* ------------------------------------------------------------------ */
    /*  PROPOSE — creative-brief proposal (pre-adoption, agent-authored)    */
    /* ------------------------------------------------------------------ */

    /**
     * PROPOSE: persist a structured creative-brief proposal that the
     * photographer may later adopt. This does NOT adopt or activate anything;
     * it only creates a persisted proposal the UI/agent can show the
     * photographer.
     *
     * @param  array<string, mixed>  $payload  structured brief (mood, story, …)
     */
    public function proposeCreativeBrief(
        Project $project,
        User $creator,
        ?CreativeConcept $source,
        string $title,
        array $payload,
    ): CreativeBrief {
        // A project with no human photographer can never adopt — so a
        // proposed brief there is inert. Reject it rather than persist an
        // undecidable proposal.
        $this->requireHumanPhotographerOnProject($project);

        return $this->creativeBriefProposal($project, $creator, $source, $title, $payload);
    }

    /* ------------------------------------------------------------------ */
    /*  HUMAN-ONLY  (photographer authority)                               */
    /* ------------------------------------------------------------------ */

    /**
     * HUMAN-ONLY. Reject a concept while preserving its history.
     * An agent account can never call this — the WebMCP catalog has no such
     * tool, and the guard is duplicated here for defense in depth.
     */
    public function rejectConcept(Project $project, User $photographer, CreativeConcept $concept): CreativeConcept
    {
        $this->assertHumanPhotographer($photographer, 'reject', $project);
        $this->assertSameProject($concept, $project);
        $this->assertMutable($concept);

        $concept->forceFill(['status' => Domain::CONCEPT_STATUS_REJECTED])->save();

        $this->recordDecision($project, $photographer, 'reject_concept', "Rejected concept #{$concept->id} ({$concept->title})");

        return $concept->fresh();
    }

    /**
     * HUMAN-ONLY. Photographer marks a concept as the one they are actively
     * exploring. A purely navigational marker — does not adopt.
     */
    public function exploreConcept(Project $project, User $photographer, CreativeConcept $concept): CreativeConcept
    {
        $this->assertHumanPhotographer($photographer, 'explore', $project);
        $this->assertSameProject($concept, $project);
        if ($concept->isAdopted()) {
            throw new \LogicException('An adopted concept cannot be re-marked as exploring.');
        }

        $concept->forceFill(['status' => Domain::CONCEPT_STATUS_EXPLORING])->save();

        return $concept->fresh();
    }

    /**
     * HUMAN-ONLY. Adopt ONE concept as the project's current creative
     * direction. The derived structured Creative Brief is persisted and the
     * project's brief (used by get_creative_brief) is updated.
     *
     * Adoption is EXCLUSIVELY a photographer decision — never an agent tool.
     */
    public function adoptConcept(
        Project $project,
        User $photographer,
        CreativeConcept $concept,
        ?string $note = null,
    ): CreativeConcept {
        $this->assertHumanPhotographer($photographer, 'adopt', $project);
        $this->assertSameProject($concept, $project);
        $this->assertMutable($concept);

        return DB::transaction(function () use ($project, $photographer, $concept, $note) {
            // Serialize concurrent adoptions for this project: every transaction
            // takes the same project-row lock before reading adopted state, so
            // two photographers adopting simultaneously transition strictly one
            // after the other and the invariant check is race-free.
            DB::table('projects')->where('id', $project->id)->lockForUpdate()->get();

            // Deterministic supersession: demote any prior adopted direction
            // to SUPERSEDED (never delete — history stays). Rejected concepts
            // were already blocked by assertMutable.
            $project->creativeConcepts()
                ->where('status', Domain::CONCEPT_STATUS_ADOPTED)
                ->where('id', '!=', $concept->id)
                ->update(['status' => Domain::CONCEPT_STATUS_SUPERSEDED]);

            $concept->forceFill([
                'status' => Domain::CONCEPT_STATUS_ADOPTED,
                'adopted_at' => now(),
            ])->save();

            // Derive + persist the structured Creative Brief.
            $this->deriveAndPersistBrief($project, $concept);

            $this->recordDecision(
                $project,
                $photographer,
                'adopt_concept',
                "Adopted creative direction: {$concept->title}" . ($note ? " — {$note}" : ''),
            );

            return $concept->fresh();
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Sprint 3 cross-sprint contract                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Answer "what does this photographer value for this project?" in a
     * machine-readable structured shape a future culling agent can consume.
     *
     * @return array{
     *   project_id: int,
     *   has_direction: bool,
     *   adopted_concept: array<string, mixed>|null,
     *   brief: array<string, mixed>|null,
     *   intent: array<string, mixed>,
     * }|null
     */
    public function structuredIntentFor(Project $project): ?array
    {
        $concept = $project->currentCreativeDirection();
        if (! $concept) {
            return null;
        }

        $state = $this->conceptState($concept);
        $brief = $this->deriveBrief($concept);

        return [
            'project_id' => $project->id,
            'has_direction' => true,
            'adopted_concept' => $state,
            'brief' => $brief,
            'intent' => $this->intentSummary($brief),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Internal helpers                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Persist a creative-brief proposal row (PROPOSE flavor).
     *
     * @param  array<string, mixed>  $payload
     */
    private function creativeBriefProposal(
        Project $project,
        User $creator,
        ?CreativeConcept $source,
        string $title,
        array $payload,
    ): CreativeBrief {
        if ($source !== null) {
            $this->assertSameProject($source, $project);
        }

        return CreativeBrief::create([
            'project_id' => $project->id,
            'client' => $payload['client'] ?? null,
            'creative_direction' => $title,
            'tonality_notes' => $payload['tonality_notes'] ?? null,
            'deliverables' => $payload['deliverables'] ?? null,
            'status' => 'proposal', // distinct from 'active' adopted briefs
            'payload' => $payload,
        ]);
    }

    /**
     * Derive a structured brief from an adopted concept and persist it as the
     * project's current creative brief (status: active). Any prior active
     * brief is superseded.
     */
    private function deriveAndPersistBrief(Project $project, CreativeConcept $concept): CreativeBrief
    {
        $brief = $this->deriveBrief($concept);

        $project->creativeBriefs()
            ->where('status', 'active')
            ->update(['status' => 'superseded']);

        return CreativeBrief::create([
            'project_id' => $project->id,
            'client' => $project->brief?->client,
            'creative_direction' => $concept->title,
            'tonality_notes' => $this->joinLines($brief['tonality_notes'] ?? []),
            'deliverables' => $this->joinLines($brief['deliverables'] ?? []),
            'status' => 'active',
            'payload' => $brief,
        ]);
    }

    /**
     * Build the derived structured brief from structured concept content.
     * This is deterministic — it maps concept dimensions onto briefing
     * dimensions. No machine-learning claims.
     *
     * @param  CreativeConcept  $concept
     * @return array<string, mixed>
     */
    private function deriveBrief(CreativeConcept $concept): array
    {
        $content = $concept->content ?? [];

        $pick = fn ($key, $default = null) => $content[$key] ?? $default;

        return [
            'mood' => $pick('mood'),
            'emotional_intent' => $pick('story') ?? $pick('emotional_intent'),
            'selection_priority' => $pick('selection_priorities'),
            'composition' => $pick('composition'),
            'lighting' => $pick('lighting'),
            'color' => $pick('color'),
            'subject_direction' => $pick('subject_direction'),
            'retouch' => $pick('retouch_philosophy'),
            'avoid' => $pick('avoid'),
            'tonality_notes' => $pick('tonality_notes'),
            'deliverables' => $pick('deliverables'),
        ];
    }

    /**
     * A compact, human-and-machine readable summary of the derived intent.
     *
     * @param  array<string, mixed>  $brief
     * @return array<string, mixed>
     */
    private function intentSummary(array $brief): array
    {
        return [
            'mood' => $brief['mood'],
            'emotional_intent' => $brief['emotional_intent'],
            'selection_priority' => $brief['selection_priority'],
            'retouch' => $brief['retouch'],
            'avoid' => $brief['avoid'],
            'philosophy' => trim(
                implode(' ', array_filter([
                    $this->joinLines($brief['mood'] ?? []),
                    $this->joinLines($brief['selection_priority'] ?? []),
                ])),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function conceptState(CreativeConcept $concept): array
    {
        return [
            'id' => $concept->id,
            'project_id' => $concept->project_id,
            'title' => $concept->title,
            'summary' => $concept->summary,
            'content' => $concept->content,
            'status' => $concept->status,
            'parent_concept_id' => $concept->parent_concept_id,
            'lineage_basis' => $concept->lineage_basis,
            'created_by' => $concept->created_by,
            'adopted_at' => $concept->adopted_at?->toISOString(),
        ];
    }

    private function recordDecision(Project $project, User $photographer, string $decision, string $note): void
    {
        // Creative Room decisions have no Proposal row; the column is nullable
        // for Sprint 2 so concept-level decisions can share the audit trail.
        PhotographerDecision::query()->create([
            'project_id' => $project->id,
            'proposal_id' => null,
            'photographer_id' => $photographer->id,
            'decision' => $decision,
            'note' => $note,
        ]);
    }

    /**
     * Defense-in-depth: enforce the human-authority boundary inside the service.
     * BOTH layers must pass:
     *  1. account-level — an `is_agent` account is never a creative authority;
     *  2. project-role   — only owner/photographer project members hold
     *     creative authority. An agent-ROLE member or viewer is forbidden even
     *     when their account-level is_agent flag is false/missing.
     * Frontend visibility, WebMCP-catalogue absence and is_agent alone are NOT
     * trusted as final authorization — this is the server-side rule.
     */
    private function assertHumanPhotographer(User $user, string $action, ?Project $project = null): void
    {
        if ($user->isAgent()) {
            throw new \LogicException("Agent accounts cannot {$action} a creative direction.");
        }

        if ($project !== null) {
            $this->assertProjectCreativeAuthority($project, $user, $action);
        }
    }

    /**
     * Project-membership authority check: only owner/photographer project
     * roles may exercise human creative actions (explore/reject/adopt/…).
     */
    private function assertProjectCreativeAuthority(Project $project, User $user, string $action): void
    {
        $role = $project->members()
            ->where('users.id', $user->id)
            ->value('project_members.role');

        if ($role === null) {
            throw new \LogicException("User #{$user->id} is not a member of this project.");
        }

        if (! in_array($role, [Domain::ROLE_OWNER, Domain::ROLE_PHOTOGRAPHER], true)) {
            throw new \LogicException("Project role [{$role}] cannot {$action} a creative direction.");
        }
    }

    /** True when the user's project role allows human creative actions. */
    public function hasCreativeAuthority(Project $project, User $user): bool
    {
        if ($user->isAgent()) {
            return false;
        }

        $role = $project->members()
            ->where('user_id', $user->id)
            ->value('project_members.role');

        return in_array($role, [Domain::ROLE_OWNER, Domain::ROLE_PHOTOGRAPHER], true);
    }

    private function assertSameProject(CreativeConcept $concept, Project $project): void
    {
        if ($concept->project_id !== $project->id) {
            throw new \LogicException('Concept does not belong to this project.');
        }
    }

    private function assertMutable(CreativeConcept $concept): void
    {
        if (in_array($concept->status, [
            Domain::CONCEPT_STATUS_REJECTED,
            Domain::CONCEPT_STATUS_ADOPTED,
        ], true)) {
            throw new \LogicException("Cannot change a concept in state [{$concept->status}].");
        }
    }

    /**
     * A project without a human photographer can never adopt a creative
     * direction. Require one so brief proposals are not inert.
     */
    private function requireHumanPhotographerOnProject(Project $project): void
    {
        $hasPhotographer = $project->members()
            ->whereIn('project_members.role', [Domain::ROLE_OWNER, Domain::ROLE_PHOTOGRAPHER])
            ->exists();

        if (! $hasPhotographer) {
            throw new \LogicException('No photographer is assigned to this project; a creative brief cannot be proposed.');
        }
    }

    private function joinLines(array|string|null $value): ?string
    {
        if (is_array($value)) {
            return trim(implode("\n", array_map('strval', $value)));
        }

        return $value === null ? null : trim((string) $value);
    }
}
