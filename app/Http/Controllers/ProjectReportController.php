<?php

namespace App\Http\Controllers;

use App\Domain\Domain;
use App\Models\PhotoDerivative;
use App\Models\Project;
use App\Services\Media\MediaStore;
use App\Services\Reports\ZipWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Session Report — the post-execution "hand the work back" surface.
 *
 * After the photographer reviews agent proposals and the agent executes the
 * approved plan, this page closes the loop: a human-readable summary of what
 * was shot, what the agent proposed, what the photographer decided, what the
 * agent executed, plus the resulting deliverable derivatives with source and
 * rendered URLs. A Markdown export downloads the same story as a portable
 * artifact (client-facing proof / archive), generated client-side from the
 * same payload so there is exactly one source of truth.
 *
 * Authority: HUMAN-ONLY, read-only, never a WebMCP tool. Mirrors the review
 * boundary — agents get 403 on every report route.
 */
class ProjectReportController extends Controller
{
    private MediaStore $media;

    public function __construct()
    {
        $this->media = app(MediaStore::class);
    }

    public function show(Request $request, Project $project): InertiaResponse
    {
        $this->authorizeMember($request, $project);

        return Inertia::render('ProjectReport', [
            'report' => $this->payload($project),
        ]);
    }

    public function markdown(Request $request, Project $project): SymfonyResponse
    {
        $this->authorizeMember($request, $project);

        $markdown = $this->toMarkdown($this->payload($project));
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($project->name));
        $filename = 'report-'.trim((string) $slug, '-').'-'.$project->id.'.md';

        return Response::make($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * GET /projects/{project}/report/deliverables.zip — packaged handoff of
     * every ACTIVE agent-executed derivative plus a SESSION-REPORT.md copy
     * of the same audit trail the page renders. Human-only like the rest of
     * the report surface.
     */
    public function deliverablesZip(Request $request, Project $project): SymfonyResponse
    {
        $this->authorizeMember($request, $project);

        $payload = $this->payload($project);
        $zip = new ZipWriter;

        // Derivatives only — reverted rows are archived experiments, not handoff.
        foreach ($payload['derivatives'] as $d) {
            if ($d['reverted_at'] !== null || $d['url'] === null || $d['storage_read_path'] === null) {
                continue;
            }
            $bytes = $this->media->read($d['storage_read_path']);
            $extension = strtolower(pathinfo((string) $d['storage_read_path'], PATHINFO_EXTENSION)) ?: 'jpg';
            $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $d['filename']);
            $zip->add("derivatives/{$safeName}-{$d['id']}.{$extension}", $bytes);
        }

        // The report travels with the deliverables.
        $zip->add('SESSION-REPORT.md', $this->toMarkdown($payload));

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($project->name));
        $filename = 'deliverables-'.trim((string) $slug, '-').'-'.$project->id.'.zip';

        return Response::make($zip->toBytes(), 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($zip->toBytes()),
        ]);
    }

    /**
     * Same member boundary as the human-only review endpoints: membership
     * required, agent accounts hard-blocked, owner/photographer roles only.
     */
    private function authorizeMember(Request $request, Project $project): void
    {
        $user = $request->user();
        $member = $project->members()->where('user_id', $user->id)->first();

        if (! $member) {
            abort(403);
        }

        if ($user->isAgent()) {
            abort(403, 'Agent accounts cannot access the session report.');
        }

        if (! in_array($member->pivot->role, [Domain::ROLE_OWNER, Domain::ROLE_PHOTOGRAPHER], true)) {
            abort(403, 'Only the photographer can view the session report.');
        }
    }

    /**
     * One payload for both the Inertia page and the Markdown export.
     * Shape mirrors WorkspacePageController's surfaces so the report reads
     * like the workspace the photographer already knows.
     *
     * @return array<string, mixed>
     */
    private function payload(Project $project): array
    {
        $photos = $project->photos()
            ->get(['id', 'filename', 'selection_state', 'retouch_state']);

        $selectionSummary = [
            'total' => $photos->count(),
            'selected' => $photos->where('selection_state', Domain::SELECTION_SELECTED)->count(),
            'culled' => $photos->where('selection_state', Domain::SELECTION_CULLED)->count(),
            'unreviewed' => $photos->where('selection_state', Domain::SELECTION_UNREVIEWED)->count(),
        ];

        $proposals = $project->proposals()
            ->with(['items.photo:id,filename', 'creator:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'type' => $p->type,
                'status' => $p->status,
                'summary' => $p->summary,
                'created_by' => $p->creator?->name,
                'reviewed_at' => $p->reviewed_at?->toISOString(),
                'executed_at' => $p->executed_at?->toISOString(),
                'items' => $p->items->map(fn ($i) => [
                    'filename' => $i->photo?->filename,
                    'action' => $i->action,
                    'rationale' => $i->rationale,
                    'status' => $i->status,
                ]),
            ]);

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

        $findings = $project->findings()
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'severity' => $f->severity,
                'category' => $f->category,
                'message' => $f->message,
                'status' => $f->status,
            ]);

        $derivatives = $project->derivatives()
            ->with(['photo:id,filename,path'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (PhotoDerivative $d) => [
                'id' => $d->id,
                'type' => $d->type,
                'filename' => $d->photo?->filename,
                'source_url' => MediaStore::publicUrl($d->photo?->path),
                'url' => MediaStore::publicUrl($d->storage_path),
                // Raw storage path for server-side reads (zip packaging).
                // read() handles both local-disk paths and trusted Blob URLs.
                'storage_read_path' => $d->storage_path,
                'adjustments' => $d->adjustments ?? [],
                'provenance' => $d->provenance,
                'reverted_at' => $d->reverted_at?->toISOString(),
                'created_at' => $d->created_at?->toISOString(),
            ]);

        $brief = $project->brief;

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
            ],
            'generated_at' => now()->toISOString(),
            'brief' => $brief === null ? null : [
                'client' => $brief->client,
                'shoot_date' => $brief->shoot_date?->toDateString(),
                'location' => $brief->location,
                'creative_direction' => $brief->creative_direction,
                'tonality_notes' => $brief->tonality_notes,
                'deliverables' => $brief->deliverables,
                'status' => $brief->status,
            ],
            'selection' => $selectionSummary,
            'counts' => [
                'proposals' => $proposals->count(),
                'proposals_executed' => $proposals->where('status', Domain::STATE_EXECUTED)->count(),
                'decisions' => $decisions->count(),
                'findings_open' => $findings->where('status', 'open')->count(),
                'derivatives_active' => $derivatives->whereNull('reverted_at')->count(),
                'derivatives_reverted' => $derivatives->whereNotNull('reverted_at')->count(),
            ],
            'proposals' => $proposals->values(),
            'decisions' => $decisions->values(),
            'findings' => $findings->values(),
            'derivatives' => $derivatives->values(),
        ];
    }

    /**
     * Deterministic Markdown rendering of the same payload — the portable
     * artifact form of the audit trail.
     *
     * @param  array<string, mixed>  $r
     */
    private function toMarkdown(array $r): string
    {
        $lines = [];
        $lines[] = '# Session Report — '.$r['project']['name'];
        $lines[] = '';
        $lines[] = 'Generated: '.($r['generated_at'] ?? now()->toISOString());
        if (! empty($r['project']['description'])) {
            $lines[] = '';
            $lines[] = (string) $r['project']['description'];
        }

        $lines[] = '';
        $lines[] = '## Selection summary';
        $lines[] = '';
        $s = $r['selection'];
        $lines[] = sprintf(
            '- Photos: %d (%d selected, %d culled, %d unreviewed)',
            $s['total'], $s['selected'], $s['culled'], $s['unreviewed']
        );
        $c = $r['counts'];
        $lines[] = sprintf('- Proposals: %d (%d executed)', $c['proposals'], $c['proposals_executed']);
        $lines[] = sprintf('- Photographer decisions: %d', $c['decisions']);
        $lines[] = sprintf('- Open QA findings: %d', $c['findings_open']);
        $lines[] = sprintf('- Deliverables: %d active (%d reverted)', $c['derivatives_active'], $c['derivatives_reverted']);

        if (! empty($r['brief'])) {
            $b = $r['brief'];
            $lines[] = '';
            $lines[] = '## Creative brief ('.($b['status'] ?? 'unknown').')';
            $lines[] = '';
            foreach (['client' => 'Client', 'shoot_date' => 'Shoot date', 'location' => 'Location'] as $k => $label) {
                if (! empty($b[$k])) {
                    $lines[] = '- '.$label.': '.$b[$k];
                }
            }
            foreach (['creative_direction' => 'Creative direction', 'tonality_notes' => 'Tonality', 'deliverables' => 'Deliverables'] as $k => $label) {
                if (! empty($b[$k])) {
                    $lines[] = '';
                    $lines[] = '**'.$label.'**: '.$b[$k];
                }
            }
        }

        $lines[] = '';
        $lines[] = '## Agent proposals';
        $lines[] = '';
        if (count($r['proposals']) === 0) {
            $lines[] = '_No proposals were made in this session._';
        }
        foreach ($r['proposals'] as $p) {
            $lines[] = sprintf(
                '- **#%d %s** — %s (%s)',
                $p['id'], $p['type'], (string) $p['summary'], $p['status']
            );
            foreach ($p['items'] as $i) {
                $lines[] = sprintf(
                    '  - %s: %s — %s',
                    (string) $i['filename'], (string) $i['action'], (string) $i['rationale']
                );
            }
        }

        $lines[] = '';
        $lines[] = '## Photographer decisions';
        $lines[] = '';
        if (count($r['decisions']) === 0) {
            $lines[] = '_No decisions recorded._';
        }
        foreach ($r['decisions'] as $d) {
            $lines[] = sprintf(
                '- %s → **%s** (proposal #%d%s)',
                (string) $d['photographer'], $d['decision'], $d['proposal_id'] ?? 0,
                isset($d['note']) && $d['note'] !== null ? '; note: '.$d['note'] : ''
            );
        }

        $lines[] = '';
        $lines[] = '## QA findings';
        $lines[] = '';
        if (count($r['findings']) === 0) {
            $lines[] = '_No QA findings._';
        }
        foreach ($r['findings'] as $f) {
            $lines[] = sprintf(
                '- [%s/%s] %s (%s)',
                (string) $f['severity'], (string) $f['category'], (string) $f['message'], $f['status']
            );
        }

        $lines[] = '';
        $lines[] = '## Deliverables (agent-executed derivatives)';
        $lines[] = '';
        if (count($r['derivatives']) === 0) {
            $lines[] = '_No derivatives were executed in this session._';
        }
        foreach ($r['derivatives'] as $d) {
            $reverted = $d['reverted_at'] !== null ? ' [REVERTED]' : '';
            $lines[] = sprintf(
                '- %s — %s%s',
                (string) $d['filename'], (string) $d['type'], $reverted
            );
            $lines[] = '  - source: '.(string) ($d['source_url'] ?? 'n/a');
            $lines[] = '  - rendered: '.(string) ($d['url'] ?? 'n/a');
            if (! empty($d['adjustments'])) {
                $pairs = [];
                foreach ($d['adjustments'] as $k => $v) {
                    $pairs[] = $k.'='.(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
                }
                $lines[] = '  - adjustments: '.implode(', ', $pairs);
            }
            $lines[] = '  - provenance: '.(string) ($d['provenance'] ?? 'n/a');
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = 'Generated by Thinking Darkroom — every value above was approved by the photographer before execution.';

        return implode("\n", $lines)."\n";
    }
}
