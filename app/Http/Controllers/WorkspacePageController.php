<?php

namespace App\Http\Controllers;

use App\Domain\Domain;
use App\Models\Project;
use App\Models\Photo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\RedirectResponse;

class WorkspacePageController extends Controller
{
    /** GET / — redirects to the seeded project (the single main workspace). */
    public function root(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $project = Project::orderBy('id')->first();

        return $project
            ? redirect()->route('workspace.show', $project)
            : redirect()->route('dashboard');
    }

    /** GET /projects/{project} — the ONE main project workspace page. */
    public function show(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        $photos = $project->photos()
            ->orderBy('id')
            ->get()
            ->map(fn (Photo $p) => [
                'id' => $p->id,
                'filename' => $p->filename,
                'url' => $p->path ? asset('storage/'.ltrim($p->path, '/')) : null,
                'mime' => $p->mime,
                'width' => $p->width,
                'height' => $p->height,
                'selection_state' => $p->selection_state,
                'retouch_state' => $p->retouch_state,
                'camera_model' => $p->camera_model,
                'iso' => $p->iso,
            ]);

        $brief = $project->brief;

        $proposals = $project->proposals()
            ->with(['items.photo:id,filename', 'creator:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'type' => $p->type,
                    'status' => $p->status,
                    'summary' => $p->summary,
                    'created_by' => $p->creator?->name,
                    'created_at' => $p->created_at?->toISOString(),
                    'reviewed_at' => $p->reviewed_at?->toISOString(),
                    'executed_at' => $p->executed_at?->toISOString(),
                    'items' => $p->items->map(fn ($i) => [
                        'id' => $i->id,
                        'photo_id' => $i->photo_id,
                        'filename' => $i->photo?->filename,
                        'kind' => $i->kind,
                        'action' => $i->action,
                        'rationale' => $i->rationale,
                        'params' => $i->params,
                        'result' => $i->result,
                        'status' => $i->status,
                    ]),
                ];
            });

        $decisions = $project->decisions()
            ->with(['photographer:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'proposal_id' => $d->proposal_id,
                'photographer' => $d->photographer?->name,
                'decision' => $d->decision,
                'note' => $d->note,
                'decided_at' => $d->created_at->toISOString(),
            ]);

        $activity = $project->toolCalls()
            ->orderByDesc('id')
            ->limit(60)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'tool_name' => $a->tool_name,
                'authority' => $a->authority,
                'result_status' => $a->result_status,
                'output_summary' => $a->output_summary,
                'created_at' => $a->created_at->toISOString(),
            ]);

        return Inertia::render('Workspace', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status,
                'owner' => $project->owner?->name,
            ],
            'brief' => $brief ? [
                'client' => $brief->client,
                'shoot_date' => $brief->shoot_date?->toDateString(),
                'location' => $brief->location,
                'creative_direction' => $brief->creative_direction,
                'tonality_notes' => $brief->tonality_notes,
                'deliverables' => $brief->deliverables,
            ] : null,
            'photos' => $photos,
            'proposals' => $proposals,
            'decisions' => $decisions,
            'activity' => $activity,
            'request' => [
                'user' => [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'is_agent' => $request->user()->isAgent(),
                ],
            ],
            'webmcp' => [
                'available' => true,
            ],
            // Sprint 3 — server-rendered context-aware culling state so the
            // first paint already carries recommendations (no extra fetch).
            // Same shape as the WebMCP get_culling_context tool response.
            'initialCulling' => (function () use ($project) {
                $culling = app(\App\Services\Culling\ContextAwareCullingService::class);
                $culling->analyzeProject($project);
                $result = $culling->recommendForProject($project);
                $result['context'] = $culling->contextSummary($project);

                return $result;
            })(),
        ]);
    }

    /** POST /projects/{project}/photos — normal JPEG upload for real demo photos. */
    public function upload(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:20'],
            'photos.*' => ['image', 'mimes:jpeg,jpg,png,webp', 'max:15360'],
        ]);

        foreach ($validated['photos'] as $file) {
            $filename = $file->hashName();
            $path = $file->store('project-'.$project->id, 'public');

            $img = null;
            $dims = null;
            try {
                $img = @getimagesize($file->getRealPath());
                $dims = $img ? ['w' => $img[0], 'h' => $img[1]] : null;
            } catch (\Throwable) {
                $dims = null;
            }

            Photo::create([
                'project_id' => $project->id,
                'filename' => $filename,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'width' => $dims['w'] ?? null,
                'height' => $dims['h'] ?? null,
                'selection_state' => Domain::SELECTION_UNREVIEWED,
                'retouch_state' => Domain::RETOUCH_NONE,
            ]);
        }

        return back()->with('flash', ['success' => 'Photos uploaded.']);
    }
}
