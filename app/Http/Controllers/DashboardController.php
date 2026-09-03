<?php

namespace App\Http\Controllers;

use App\Domain\Domain;
use App\Models\AgentPresence;
use App\Models\Project;
use App\Models\User;
use App\Services\AgentPresenceService;
use App\Support\WebmcpToolCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $projects = Project::whereHas('members', fn ($q) => $q->where('user_id', $user->id))
            ->withCount([
                'photos',
                'proposals as pending_proposals_count' => fn ($q) => $q->where('status', Domain::STATE_PENDING_REVIEW),
                'proposals as approved_proposals_count' => fn ($q) => $q->whereIn('status', [Domain::STATE_APPROVED, Domain::STATE_MODIFIED]),
                'proposals as executed_proposals_count' => fn ($q) => $q->where('status', Domain::STATE_EXECUTED),
            ])
            ->withMax('photos as last_photo_at', 'created_at')
            ->orderByDesc('id')
            ->get();

        // Canonical payload shape — asserted key-for-key by
        // tests/Feature/DashboardTest.php. The seven keys below are the public
        // contract; view-only extensions live in the separate `project_meta`
        // prop, never inside these items.
        $projectPayloads = $projects->map(fn (Project $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'status' => $p->status,
            'photo_count' => $p->photos_count,
            'pending_proposals' => $p->pending_proposals_count,
            'url' => route('workspace.show', $p),
        ]);

        $projectMeta = $projects->mapWithKeys(fn (Project $p) => [$p->id => [
            'description' => $p->description,
            'can_manage' => $p->owner_id === $user->id,
            'approved_proposals' => $p->approved_proposals_count,
            'executed_proposals' => $p->executed_proposals_count,
            'last_photo_at' => $p->last_photo_at
                ? Carbon::parse($p->last_photo_at)->toIso8601String()
                : null,
        ]]);

        // Tool inventory comes from the authoritative server-side catalog so
        // the dashboard can never drift from what the registry actually
        // exposes. `all()` includes the dynamic EXECUTE tool, which the UI
        // labels as approval-gated rather than always-available.
        $catalog = WebmcpToolCatalog::all();

        $byAuthority = [
            Domain::AUTHORITY_READ => 0,
            Domain::AUTHORITY_ANALYZE => 0,
            Domain::AUTHORITY_PROPOSE => 0,
            Domain::AUTHORITY_EXECUTE => 0,
        ];
        foreach ($catalog as $tool) {
            if (isset($byAuthority[$tool['authority']])) {
                $byAuthority[$tool['authority']]++;
            }
        }

        $dynamic = collect($catalog)->first(fn ($tool) => $tool['dynamic'] === true);

        $projectIds = $user->projects()->select('projects.id')->pluck('projects.id');

        $agents = User::query()
            ->join('project_members', 'project_members.user_id', '=', 'users.id')
            ->whereIn('project_members.project_id', $projectIds)
            ->where('project_members.role', Domain::ROLE_AGENT)
            ->where('users.is_agent', true)
            ->orderBy('users.id')
            ->select('users.id', 'users.name')
            ->distinct()
            ->get();
        $agent = $agents->first();

        $lastSeenAt = $agents->isNotEmpty()
            ? AgentPresence::query()
                ->whereIn('user_id', $agents->pluck('id'))
                ->whereIn('project_id', $projectIds)
                ->max('last_seen_at')
            : null;

        $online = $lastSeenAt !== null
            && Carbon::parse($lastSeenAt)->greaterThan(now()->subSeconds(AgentPresenceService::ONLINE_TTL_SECONDS));

        return Inertia::render('Dashboard', [
            'projects' => $projectPayloads,
            'can_create_project' => $user->can('create', Project::class),
            'project_meta' => $projectMeta,
            'tools' => [
                'total' => count($catalog),
                'byAuthority' => $byAuthority,
                'dynamic' => $dynamic !== null ? [
                    'name' => $dynamic['name'],
                    'description' => $dynamic['description'],
                ] : null,
            ],
            'agent' => [
                'name' => $agent?->name,
                'online' => $online,
                'last_seen_at' => $lastSeenAt !== null ? Carbon::parse($lastSeenAt)->toIso8601String() : null,
            ],
            'now' => now()->toIso8601String(),
        ]);
    }
}
