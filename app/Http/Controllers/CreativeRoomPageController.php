<?php

namespace App\Http\Controllers;

use App\Domain\Domain;
use App\Models\Project;
use App\Services\AgentConversationService;
use App\Services\AgentPresenceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 2 — Creative Room page (Inertia).
 *
 * A visual creative workspace (NOT a chat screen): creative canvas on top,
 * concept cards with lineage in the middle, agent collaboration panel on the
 * right. All state-changing actions route through CreativeRoomReviewController
 * (human-only) or the WebMCP CreativeRoomController (agent).
 */
class CreativeRoomPageController extends Controller
{
    public function show(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        $user = $request->user();

        $myRole = $project->members()
            ->where('user_id', $user->id)
            ->value('project_members.role');
        $presenceEligible = $user->isAgent() && $myRole === Domain::ROLE_AGENT;
        $canChat = $user->can('message', $project);
        $presence = app(AgentPresenceService::class)->forProject($project);
        $conversation = app(AgentConversationService::class)->forProject($project);

        $concepts = $project->creativeConcepts()
            ->with(['items', 'creator:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'summary' => $c->summary,
                'content' => $c->content,
                'status' => $c->status,
                'parent_concept_id' => $c->parent_concept_id,
                'lineage_basis' => $c->lineage_basis,
                'created_by' => $c->created_by,
                'creator_name' => $c->creator?->name,
                'creator_is_agent' => (bool) $c->creator?->is_agent,
                'adopted_at' => $c->adopted_at?->toISOString(),
                'created_at' => $c->created_at?->toISOString(),
                'items' => $c->items->map(fn ($i) => [
                    'id' => $i->id,
                    'dimension' => $i->dimension,
                    'label' => $i->label,
                    'value' => $i->value,
                    'source' => $i->source,
                ])->values(),
            ])
            ->values();

        $brainstorm = $project->brainstormSessions()
            ->with('photographer:id,name')
            ->latest('id')
            ->first();

        $direction = $project->currentCreativeDirection();

        $brief = $project->brief;

        $agentActivity = $project->toolCalls()
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'tool_name' => $a->tool_name,
                'authority' => $a->authority,
                'result_status' => $a->result_status,
                'output_summary' => $a->output_summary,
                'created_at' => $a->created_at->toISOString(),
            ]);

        return Inertia::render('CreativeRoom', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status,
                'owner' => $project->owner?->name,
            ],
            'request' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'is_agent' => $user->isAgent(),
                    'presence_eligible' => $presenceEligible,
                ],
            ],
            'my_role' => $myRole,
            'can_review' => $user->isAgent()
                ? false
                : in_array($myRole, [Domain::ROLE_OWNER, Domain::ROLE_PHOTOGRAPHER], true),
            'brainstorm' => $brainstorm ? [
                'id' => $brainstorm->id,
                'input' => $brainstorm->input,
                'status' => $brainstorm->status,
                'photographer' => $brainstorm->photographer?->name,
                'created_at' => $brainstorm->created_at->toISOString(),
            ] : null,
            'concepts' => $concepts,
            'adopted_concept_id' => $direction?->id,
            'brief' => $brief && $brief->status === 'active' ? [
                'id' => $brief->id,
                'creative_direction' => $brief->creative_direction,
                'payload' => $brief->payload,
                'adopted_at' => $brief->created_at?->toISOString(),
            ] : null,
            'agent_activity' => $agentActivity,
            'presence' => $presence,
            'conversation' => $conversation,
            'permissions' => [
                'can_chat' => $canChat,
            ],
            'webmcp' => [
                'available' => true,
            ],
            // C15: the dynamic apply_approved_plan tool must reconcile with the
            // real proposal lifecycle, not a permanently-null stub.
            'eligible_proposal_id' => $project->executableProposals()->orderBy('id')->value('id'),
        ]);
    }
}
