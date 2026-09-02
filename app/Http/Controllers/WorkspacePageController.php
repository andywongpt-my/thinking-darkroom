<?php

namespace App\Http\Controllers;

use App\Domain\Domain;
use App\Models\Photo;
use App\Models\PhotoDerivative;
use App\Models\Project;
use App\Services\AgentConversationService;
use App\Services\AgentPresenceService;
use App\Services\Culling\ContextAwareCullingService;
use App\Services\Media\MediaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class WorkspacePageController extends Controller
{
    /** GET / — redirects to the newest project the signed-in user can access. */
    public function root(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $user = $request->user();
        if ($user === null) {
            return redirect()->route('login');
        }

        $project = Project::whereHas('members', fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('id')
            ->first();

        return $project
            ? redirect()->route('workspace.show', $project)
            : redirect()->route('dashboard');
    }

    /** GET /projects/{project} — the ONE main project workspace page. */
    public function show(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        $user = $request->user();
        $role = $project->members()
            ->where('project_members.user_id', $user->id)
            ->value('project_members.role');
        $canPhotographerAct = ! $user->isAgent() && in_array($role, [
            Domain::ROLE_OWNER,
            Domain::ROLE_PHOTOGRAPHER,
        ], true);
        $presenceEligible = $user->isAgent() && $role === Domain::ROLE_AGENT;
        $canChat = $user->can('message', $project);
        $presence = app(AgentPresenceService::class)->forProject($project);
        $conversation = app(AgentConversationService::class)->forProject($project);

        $photos = $project->photos()
            ->orderBy('id')
            ->get()
            ->map(fn (Photo $p) => [
                'id' => $p->id,
                'filename' => $p->filename,
                'url' => MediaStore::publicUrl($p->path),
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

        // Sprint 4 — persisted QA findings for the Consistency QA panel.
        $qaFindings = $project->findings()
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'severity' => $f->severity,
                'category' => $f->category,
                'message' => $f->message,
                'photo_id' => $f->photo_id,
                'status' => $f->status,
                'details' => $f->details,
            ])
            ->values()
            ->all();

        // Sprint 4 — persisted photographer decision history (Creative Memory).
        // Explicit photographer-authored lessons; deterministic context for
        // future proposals. NOT machine-learned personalization.
        $creativeMemories = $project->creativeMemories()
            ->with('photographer:id,name')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'kind' => $m->kind,
                'lesson' => $m->lesson,
                'photographer' => $m->photographer?->name,
                'created_at' => $m->created_at?->toISOString(),
            ])
            ->values();

        // Sprint 4 — retouch truth card for the initially-selected photo. The
        // browser fetches one project/photo card when the selection changes.
        $retouch = $this->retouchCard($project, $photos->first()['id'] ?? null);

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
                    'id' => $user->id,
                    'name' => $user->name,
                    'is_agent' => $user->isAgent(),
                    'presence_eligible' => $presenceEligible,
                ],
            ],
            'presence' => $presence,
            'conversation' => $conversation,
            'permissions' => [
                'can_upload' => $canPhotographerAct,
                'can_photographer_act' => $canPhotographerAct,
                'can_execute' => $role !== Domain::ROLE_VIEWER,
                'can_chat' => $canChat,
            ],
            'webmcp' => [
                'available' => true,
            ],
            // Sprint 3 — server-rendered context-aware culling state so the
            // first paint already carries recommendations (no extra fetch).
            // Same shape as the WebMCP get_culling_context tool response.
            'initialCulling' => (function () use ($project) {
                $culling = app(ContextAwareCullingService::class);
                $result = $culling->recommendForProject($project);
                $result['context'] = $culling->contextSummary($project);

                return $result;
            })(),
            // Sprint 4 — retouch / QA / creative-memory state for the panels.
            'retouchCard' => $retouch,
            'qaFindings' => $qaFindings,
            'creativeMemories' => $creativeMemories,
        ]);
    }

    /** GET /projects/{project}/photos/{photo}/retouch-card. */
    public function retouchCardForPhoto(Request $request, Project $project, Photo $photo): JsonResponse
    {
        $this->authorize('view', $project);
        abort_unless($photo->project_id === $project->id, 404);

        return response()->json([
            'retouch_card' => $this->retouchCard($project, $photo->id),
        ]);
    }

    /**
     * Sprint 4 — the retouch truth card payload: three-layer adjustment
     * history (AGENT ORIGINAL → PHOTOGRAPHER MODIFICATION → EXECUTED) plus
     * real before/after evidence (URLs, checksums, dimensions) for the most
     * most recent retouch proposal chain for one project photo.
     *
     * Everything here is read straight from the database and the storage
     * disk — the UI never fabricates values.
     *
     * @return array<string, mixed>|null
     */
    private function retouchCard(Project $project, ?int $photoId): ?array
    {
        if ($photoId === null) {
            return null;
        }

        $photo = Photo::query()
            ->where('project_id', $project->id)
            ->whereKey($photoId)
            ->first();

        if ($photo === null) {
            return null;
        }

        $chain = $project->proposals()
            ->whereIn('type', [Domain::TYPE_RETOUCH, Domain::TYPE_BATCH_RETOUCH])
            ->whereHas('items', fn ($query) => $query->where('photo_id', $photoId))
            ->with(['items' => fn ($query) => $query
                ->where('photo_id', $photoId)
                ->with('photo:id,filename,path,width,height')])
            ->orderByDesc('id')
            ->limit(6)
            ->get();

        if ($chain->isEmpty()) {
            return null;
        }

        // Prefer the executed member of the chain; fall back to the newest.
        $proposal = $chain->firstWhere('executed_at', '!==', null) ?? $chain->first();

        $item = $proposal->items->first();
        if ($item === null) {
            return null;
        }

        $originalPath = $photo->path;
        $media = app(MediaStore::class);

        // Agent-original values: the superseding proposal's payload preserves
        // them byte-for-byte; otherwise the proposal's own item params.
        // PROJECTION: item params also carry read-only brief-awareness
        // evidence (brief_aware, derived_adjustments, adjustments_summary,
        // retouch_influenced_by, retouch_note) — the three-layer value
        // history must present ONLY the executable adjustment numbers, never
        // the metadata block.
        $executableKeys = array_fill_keys(Domain::RETOUCH_ADJUSTMENTS, true);
        $projectAdjustments = fn ($params) => collect(is_array($params) ? $params : [])
            ->filter(fn ($v, $k) => array_key_exists($k, $executableKeys) && is_numeric($v))
            ->map(fn ($v) => (float) $v)
            ->all();

        $parent = $proposal->supersedes_id !== null
            ? $project->proposals()
                ->with(['items' => fn ($query) => $query->where('photo_id', $photoId)])
                ->find($proposal->supersedes_id)
            : null;
        $originalItem = collect(data_get($proposal->payload, 'original_items', []))
            ->first(fn ($original) => is_array($original) && ($original['photo_id'] ?? null) === $photoId);
        $agentSource = is_array($originalItem) ? ($originalItem['params'] ?? null) : null;
        $agentSource ??= $parent?->items->first()?->params;
        $agentSource ??= $item->params;
        $agentOriginal = $projectAdjustments($agentSource);

        // Photographer modification: the superseded proposal's modify decision.
        $modification = null;
        $decision = $project->decisions()
            ->where('proposal_id', $proposal->supersedes_id ?? $proposal->id)
            ->where('decision', 'modify')
            ->orderByDesc('id')
            ->first();
        if ($decision !== null) {
            $mods = $decision->modifications ?? [];
            $modification = [
                'adjustments' => $mods['adjustments'] ?? ($mods['params'] ?? null),
                'note' => $decision->note,
            ];
        }

        // Executed values: the persisted derivative adjustments (source of
        // truth for what actually ran), with the item result as fallback.
        $derivative = $originalPath
            ? PhotoDerivative::where('photo_id', $item->photo_id)
                ->where('project_id', $project->id)
                ->where('type', Domain::DERIVATIVE_APPROVED_RENDER)
                ->where('proposal_id', $proposal->id)
                ->first()
            : null;

        $executed = $derivative?->adjustments
            ?? data_get($item->result, 'operations')
            ?? null;

        try {
            $originalSha = $originalPath ? hash('sha256', (string) $media->read($originalPath)) : null;
        } catch (Throwable) {
            $originalSha = null;
        }
        $derivativeSha = null;
        $derivativeUrl = null;
        if ($derivative) {
            try {
                $derivativeSha = hash('sha256', (string) $media->read($derivative->storage_path));
            } catch (Throwable) {
                $derivativeSha = null;
            }
            $derivativeUrl = MediaStore::publicUrl($derivative->storage_path);
        }

        return [
            'proposal_id' => $proposal->id,
            'status' => $proposal->status,
            'photo' => $photo ? [
                'id' => $photo->id,
                'filename' => $photo->filename,
                'width' => $photo->width,
                'height' => $photo->height,
            ] : null,
            'original' => [
                'url' => $photo && $photo->path ? MediaStore::publicUrl($photo->path) : null,
                'sha256' => $originalSha,
            ],
            'derivative' => $derivative ? [
                'url' => $derivativeUrl,
                'sha256' => $derivativeSha,
                'storage_path' => $derivative->storage_path,
                'adjustments' => $derivative->adjustments,
                'provenance' => $derivative->provenance,
                'proposal_id' => $derivative->proposal_id,
            ] : null,
            'agent_original' => [
                'params' => $agentOriginal,
                'influenced_by' => data_get($agentSource, 'retouch_influenced_by', []),
                'brief_aware' => (bool) data_get($agentSource, 'brief_aware', false),
                'note' => data_get($agentSource, 'retouch_note'),
            ],
            'photographer_modification' => $modification,
            'executed' => $executed !== null ? [
                'params' => $executed,
                'at' => $proposal->executed_at?->toISOString(),
            ] : null,
        ];
    }

    /** POST /projects/{project}/photos — normal JPEG upload for real demo photos. */
    public function upload(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('upload', $project);

        $validated = $request->validate([
            // Vercel caps request bodies at 4.5MB — keep a single-file request
            // under the edge cap (4.3MB per file, ≤10 files per batch).
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['image', 'mimes:jpeg,jpg,png,webp', 'max:4400'],
        ]);

        $media = app(MediaStore::class);

        // Honest batch budget (Sol P1-3): Vercel caps the whole request body
        // at 4.5MB, so a 10×4.4MB advertisement could never survive the edge.
        // Enforce the real aggregate budget before any bytes are written.
        $totalBytes = collect($validated['photos'])->sum(fn ($f) => (int) $f->getSize());
        if ($totalBytes > 4_300_000) {
            throw ValidationException::withMessages([
                'photos' => 'This batch is '.round($totalBytes / 1_000_000, 1).'MB — the upload budget is 4.3MB per request. Upload fewer or smaller files.',
            ]);
        }

        foreach ($validated['photos'] as $file) {
            // Store the raw bytes (Vercel Blob when durable, public disk otherwise),
            // then persist the DB row in a second step so a failed storage write
            // can never produce a photo record whose bytes are missing.
            $stored = $media->write('project-'.$project->id, $file);

            $img = null;
            $dims = null;
            try {
                $img = @getimagesize($file->getRealPath());
                $dims = $img ? ['w' => $img[0], 'h' => $img[1]] : null;
            } catch (Throwable) {
                $dims = null;
            }

            try {
                Photo::create([
                    'project_id' => $project->id,
                    'filename' => $file->hashName(),
                    'original_name' => $file->getClientOriginalName(),
                    'path' => $media->recordPath($stored),
                    'mime' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'width' => $dims['w'] ?? null,
                    'height' => $dims['h'] ?? null,
                    'selection_state' => Domain::SELECTION_UNREVIEWED,
                    'retouch_state' => Domain::RETOUCH_NONE,
                ]);
            } catch (Throwable $e) {
                // Compensating delete: never orphan uploaded bytes behind a
                // failed row insert (Sol P2: partial-failure compensation).
                // recordPath() yields the canonical handle (absolute Blob URL
                // in durable mode) — the raw relative path would make the
                // remote delete a no-op and orphan billable bytes (Sol P1-4).
                $media->delete($media->recordPath($stored));

                throw $e;
            }
        }

        return back()->with('flash', ['success' => 'Photos uploaded.']);
    }

    /** DELETE /projects/{project}/photos/{photo} — remove one original and its derivatives. */
    public function destroyPhoto(Project $project, Photo $photo): RedirectResponse
    {
        $this->authorize('upload', $project);

        abort_unless($photo->project_id === $project->id, 404);

        $media = app(MediaStore::class);
        $paths = $this->mediaPathsForPhoto($photo);

        try {
            $this->deleteMediaOrFail($media, $paths);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors([
                'media' => 'Unable to delete photo media. The photo was not deleted.',
            ]);
        }

        try {
            $deleted = DB::transaction(fn (): bool => $photo->delete() === true);
            if (! $deleted) {
                throw new RuntimeException('Photo deletion was vetoed or did not remove a database row.');
            }
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors([
                'photo' => 'Photo records could not be deleted after media cleanup. Media may have been removed; please retry or contact support.',
            ]);
        }

        return back()->with('flash', ['success' => 'Photo deleted.']);
    }

    /**
     * @return list<string>
     */
    private function mediaPathsForPhoto(Photo $photo): array
    {
        return collect([
            ...PhotoDerivative::query()
                ->where('photo_id', $photo->id)
                ->pluck('storage_path')
                ->all(),
            $photo->path,
        ])
            ->filter(fn ($path): bool => $this->deletablePath($path))
            ->unique()
            ->values()
            ->all();
    }

    private function deletablePath(mixed $path): bool
    {
        return is_string($path) && $path !== '';
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteMediaOrFail(MediaStore $mediaStore, array $paths): void
    {
        foreach ($paths as $path) {
            if (! $mediaStore->delete($path) && $mediaStore->exists($path)) {
                throw new RuntimeException('Media deletion returned an unsuccessful result.');
            }
        }
    }
}
