import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useWebmcpRegistry } from '@/webmcp/use-webmcp';
import { webmcpApi } from '@/webmcp/api';
import type { PhotoSummary, ProposalItemPayload, ProposalPayload } from '@/webmcp/api';

interface WorkspacePhoto extends PhotoSummary {
    camera_model?: string | null;
    iso?: number | null;
    original_name?: string | null;
    lens?: string | null;
    aperture?: string | null;
    shutter_speed?: string | null;
    focal_length?: string | null;
    dimensions?: string | null;
}

interface WorkspaceBrief {
    client: string | null;
    shoot_date: string | null;
    location: string | null;
    creative_direction: string | null;
    tonality_notes: string | null;
    deliverables: string | null;
}

interface WorkspaceProposal {
    id: number;
    type: string;
    status: string;
    summary: string | null;
    created_by: string | null;
    created_at: string | null;
    reviewed_at: string | null;
    executed_at: string | null;
    items: ProposalItemPayload[];
}

interface WorkspaceDecision {
    id: number;
    proposal_id: number | null;
    photographer: string | null;
    decision: string;
    note: string | null;
    decided_at: string;
}

interface ActivityEntry {
    id: number;
    tool_name: string;
    authority: string;
    result_status: string;
    output_summary: Record<string, unknown> | null;
    created_at: string;
}

interface PageProps extends Record<string, unknown> {
    auth: { user: { id: number; name: string; email: string } };
    project: {
        id: number;
        name: string;
        description: string | null;
        status: string;
        owner: string | null;
    };
    brief: WorkspaceBrief | null;
    photos: WorkspacePhoto[];
    proposals: WorkspaceProposal[];
    decisions: WorkspaceDecision[];
    activity: ActivityEntry[];
    request: {
        user: { id: number; name: string; is_agent: boolean };
    };
    webmcp: { available: boolean };
    flash?: { success?: string };
}

const TYPE_LABEL: Record<string, string> = {
    cull: 'Cull',
    retouch: 'Retouch plan',
    batch_retouch: 'Batch retouch',
    qa_resolution: 'QA resolution',
};

const STATE_LABEL: Record<string, string> = {
    draft: 'Draft',
    pending_review: 'Pending review',
    approved: 'Approved',
    modified: 'Modified',
    rejected: 'Rejected',
    executed: 'Executed',
};

const AUTHORITY_COLOR: Record<string, string> = {
    READ: 'bg-sky-100 text-sky-800',
    PROPOSE: 'bg-amber-100 text-amber-800',
    EXECUTE: 'bg-emerald-100 text-emerald-800',
};

const STATUS_DOT: Record<string, string> = {
    completed: 'bg-emerald-500',
    denied: 'bg-rose-500',
    failed: 'bg-rose-500',
    error: 'bg-rose-500',
};

function fmtTime(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString();
}

export default function Workspace() {
    const page = usePage<PageProps>();
    const { project, brief, photos, proposals, decisions, activity, request, flash } = page.props;

    const isAgent = request.user.is_agent;

    const [selectedId, setSelectedId] = useState<number | null>(photos[0]?.id ?? null);
    const [localProposals, setLocalProposals] = useState<WorkspaceProposal[]>(proposals);
    const [localActivity, setLocalActivity] = useState<ActivityEntry[]>(activity);
    const [references, setReferences] = useState<unknown[]>([]);
    const [cullIds, setCullIds] = useState<number[]>([]);
    const [busy, setBusy] = useState<string | null>(null);
    const [diagOpen, setDiagOpen] = useState(false);
    const [notify, setNotify] = useState<{ kind: 'ok' | 'err'; text: string } | null>(null);
    const uploadRef = useRef<HTMLInputElement>(null);

    // The single eligible approved, unexecuted proposal (drives dynamic tool).
    const eligibleProposal = useMemo(
        () => localProposals.find((p) => p.status === 'approved' && !p.executed_at) ?? null,
        [localProposals],
    );

    const { registry, snapshot, eligibleForExecution } = useWebmcpRegistry(
        project.id,
        eligibleProposal ? eligibleProposal.id : null,
    );

    const selected = photos.find((p) => p.id === selectedId) ?? null;

    const refreshState = async () => {
        setBusy('refresh');
        try {
            const [ctx, ph] = await Promise.all([
                webmcpApi.getWorkspaceContext(project.id),
                webmcpApi.listProjectPhotos(project.id),
            ]);
            if (ph.ok && ph.data) {
                // merge any fresh inspection fields (keep selection_state in sync)
                setSelectedId((cur) => (ph.data!.photos.some((p) => p.id === cur) ? cur : ph.data!.photos[0]?.id ?? null));
                window.location.reload();
            }
            if (ctx.ok) setNotify({ kind: 'ok', text: `Workspace refreshed — ${ctx.data?.counts.total ?? '?'} photos.` });
        } finally {
            setBusy(null);
        }
    };

    // Eager per-photo inspect so the centre panel is rich.
    const eager = useMemo(() => new Map<number, Partial<WorkspacePhoto>>(), []);
    useEffect(() => {
        let live = true;
        photos.slice(0, 8).forEach((p) => {
            webmcpApi.inspectPhoto(project.id, p.id).then((res) => {
                if (!live || !res.ok || !res.data) return;
                eager.set(p.id, res.data.photo);
                setReferences((r) => [...r]);
            });
        });
        return () => {
            live = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [project.id, photos.length]);

    const addActivity = (entry: Partial<ActivityEntry>) => {
        const full: ActivityEntry = {
            id: Date.now(),
            tool_name: 'ui',
            authority: 'READ',
            result_status: 'completed',
            output_summary: null,
            created_at: new Date().toISOString(),
            ...entry,
        };
        setLocalActivity((a) => [full, ...a].slice(0, 80));
    };

    const normalizeProposal = (
        p: WorkspaceProposal | ProposalPayload['proposal'],
    ): WorkspaceProposal => {
        const created_by =
            typeof p.created_by === 'number'
                ? String(p.created_by)
                : (p.created_by ?? null);
        return { ...p, created_by };
    };

    const prependProposal = (p: ProposalPayload['proposal']) => {
        setLocalProposals((cur) => [normalizeProposal(p), ...cur]);
    };

    const runProposeCull = async () => {
        if (cullIds.length === 0) {
            setNotify({ kind: 'err', text: 'Select at least one photo (click a photo, then tick the checkbox).' });
            return;
        }
        setBusy('cull');
        const res = await webmcpApi.proposeCull(
            project.id,
            cullIds.map((pid) => ({ photo_id: pid, action: 'cull', rationale: 'Suggested cull (edge/soft focus).' })),
            `Cull ${cullIds.length} frame(s) suggested by the agent.`,
        );
        setBusy(null);
        if (res.ok && res.data) {
            prependProposal(res.data.proposal);
            addActivity({ tool_name: 'propose_cull', authority: 'PROPOSE', result_status: 'completed', output_summary: { proposal_id: res.data.proposal.id, items: res.data.proposal.items.length } });
            setNotify({ kind: 'ok', text: `Cull proposal #${res.data.proposal.id} created (pending review).` });
            setCullIds([]);
        } else {
            addActivity({ tool_name: 'propose_cull', authority: 'PROPOSE', result_status: 'error', output_summary: { error: res.error } });
            setNotify({ kind: 'err', text: `propose_cull failed: ${res.error}` });
        }
    };

    const runRetouchPlan = async () => {
        const target = selected || photos[0];
        if (!target) return;
        setBusy('retouch');
        const res = await webmcpApi.proposeRetouchPlan(
            project.id,
            [{ photo_id: target.id, action: 'exposure', params: { exposure: +0.3, contrast: 0.05 }, rationale: 'Balanced exposure pass.' }],
            'Proposed exposure retouch for preview.',
        );
        setBusy(null);
        if (res.ok && res.data) {
            prependProposal(res.data.proposal);
            addActivity({ tool_name: 'propose_retouch_plan', authority: 'PROPOSE', result_status: 'completed', output_summary: { proposal_id: res.data.proposal.id } });
            setNotify({ kind: 'ok', text: `Retouch proposal #${res.data.proposal.id} created.` });
        } else {
            addActivity({ tool_name: 'propose_retouch_plan', authority: 'PROPOSE', result_status: 'error', output_summary: { error: res.error } });
            setNotify({ kind: 'err', text: `propose_retouch_plan failed: ${res.error}` });
        }
    };

    const runReview = async () => {
        setBusy('review');
        const res = await webmcpApi.runConsistencyReview(project.id, 'selected');
        setBusy(null);
        if (res.ok && res.data) {
            addActivity({ tool_name: 'run_consistency_review', authority: 'PROPOSE', result_status: 'completed', output_summary: { findings: res.data.created_findings.length } });
            setNotify({ kind: 'ok', text: `Consistency review done — ${res.data.created_findings.length} finding(s).` });
        } else {
            setNotify({ kind: 'err', text: `run_consistency_review failed: ${res.error}` });
        }
    };

    const humanApprove = async (proposal: WorkspaceProposal) => {
        setBusy('approve');
        try {
            const resp = await fetch(route('proposals.approve', [project.id, proposal.id]), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '' },
            });
            const j = await resp.json().catch(() => null);
            if (resp.ok && j?.proposal) {
                setLocalProposals((ps) => ps.map((p) => (p.id === proposal.id ? { ...p, status: 'approved' } : p)));
                addActivity({ tool_name: 'photographer_approve', authority: 'EXECUTE', result_status: 'completed', output_summary: { proposal_id: proposal.id, by: request.user.name } });
                setNotify({ kind: 'ok', text: `Proposal #${proposal.id} approved — apply_approved_plan is now registered.` });
            } else {
                setNotify({ kind: 'err', text: `Approval failed: ${j?.error ?? resp.statusText}` });
            }
        } finally {
            setBusy(null);
        }
    };

    const humanReject = async (proposal: WorkspaceProposal) => {
        setBusy('reject');
        const resp = await fetch(route('proposals.reject', [project.id, proposal.id]), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '' },
        });
        const j = await resp.json().catch(() => null);
        setBusy(null);
        if (resp.ok) {
            setLocalProposals((ps) => ps.map((p) => (p.id === proposal.id ? { ...p, status: 'rejected' } : p)));
            addActivity({ tool_name: 'photographer_reject', authority: 'READ', result_status: 'completed', output_summary: { proposal_id: proposal.id, by: request.user.name } });
            setNotify({ kind: 'ok', text: `Proposal #${proposal.id} rejected.` });
        } else {
            setNotify({ kind: 'err', text: `Reject failed: ${j?.error ?? resp.statusText}` });
        }
    };

    const runExecute = async () => {
        if (!eligibleProposal) {
            setNotify({ kind: 'err', text: 'No eligible approved proposal to execute.' });
            return;
        }
        setBusy('execute');
        const res = await webmcpApi.applyApprovedPlan(project.id, eligibleProposal.id);
        setBusy(null);
        if (res.ok && res.data) {
            setLocalProposals((ps) => ps.map((p) => (p.id === eligibleProposal!.id ? { ...p, status: 'executed', executed_at: new Date().toISOString() } : p)));
            addActivity({ tool_name: 'apply_approved_plan', authority: 'EXECUTE', result_status: 'completed', output_summary: { proposal_id: eligibleProposal.id } });
            registry?.markExecuted();
            setNotify({ kind: 'ok', text: `Proposal #${eligibleProposal.id} executed — apply_approved_plan removed.` });
            // refresh photo state from server
            webmcpApi.listProjectPhotos(project.id).then((r) => {
                if (r.ok && r.data) window.location.reload();
            });
        } else {
            addActivity({ tool_name: 'apply_approved_plan', authority: 'EXECUTE', result_status: 'error', output_summary: { error: res.error, proposal_id: eligibleProposal.id } });
            setNotify({ kind: 'err', text: `execute failed: ${res.error}` });
        }
    };

    const doUpload = (files: FileList | null) => {
        if (!files || files.length === 0) return;
        const form = new FormData();
        Array.from(files).forEach((f) => form.append('photos[]', f));
        setBusy('upload');
        router.post(route('workspace.upload', project.id), form, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setBusy(null);
                setNotify({ kind: 'ok', text: `${files.length} photo(s) uploaded.` });
                window.location.reload();
            },
            onError: () => {
                setBusy(null);
                setNotify({ kind: 'err', text: 'Upload failed.' });
            },
        });
    };

    const toggleCull = (id: number) => {
        setCullIds((c) => (c.includes(id) ? c.filter((x) => x !== id) : [...c, id]));
    };

    const webmcpUnavailable = !(snapshot?.webmcpAvailable ?? false) && !(snapshot?.usingFallback ?? false);

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">{project.name}</h2>}>
            <Head title={project.name} />

            <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                {/* WebMCP availability banner */}
                {webmcpUnavailable && (
                    <div className="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                        <strong>WebMCP is not available in this browser.</strong> The app loads normally, but
                        agent tools will not be registered on <code>document.modelContext</code>.
                    </div>
                )}
                {flash?.success && (
                    <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{flash.success}</div>
                )}
                {notify && (
                    <div className={`mb-4 rounded-lg border px-4 py-3 text-sm ${notify.kind === 'ok' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700'}`}>
                        {notify.text}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-5 lg:grid-cols-[240px_1fr_320px]">
                    {/* ============ LEFT: photo grid ============ */}
                    <section className="rounded-xl border border-gray-200 bg-white p-3 shadow-sm">
                        <div className="mb-2 flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-gray-800">Photos</h3>
                            <button
                                onClick={() => uploadRef.current?.click()}
                                disabled={busy !== null}
                                className="rounded-md bg-gray-800 px-2 py-1 text-xs font-medium text-white hover:bg-gray-700 disabled:opacity-40"
                            >
                                {busy === 'upload' ? 'Uploading…' : '+ Upload'}
                            </button>
                            <input
                                ref={uploadRef}
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                multiple
                                className="hidden"
                                onChange={(e) => doUpload(e.target.files)}
                            />
                        </div>
                        <div className="grid grid-cols-3 gap-2">
                            {photos.map((p) => (
                                <div key={p.id} className="group relative">
                                    <button
                                        onClick={() => setSelectedId(p.id)}
                                        className={`w-full overflow-hidden rounded-md border-2 ${selectedId === p.id ? 'border-amber-500' : 'border-transparent'} ${p.selection_state === 'culled' ? 'opacity-50' : ''}`}
                                    >
                                        {p.url ? (
                                            <img src={p.url} alt={p.filename} className="aspect-square w-full object-cover" loading="lazy" />
                                        ) : (
                                            <div className="flex aspect-square w-full items-center justify-center bg-gray-200 text-xs text-gray-500">no img</div>
                                        )}
                                    </button>
                                    {p.selection_state === 'culled' && (
                                        <span className="absolute left-1 top-1 rounded bg-rose-600 px-1 text-[10px] font-bold text-white">CULL</span>
                                    )}
                                    <label className="absolute right-1 top-1 cursor-pointer rounded bg-black/50 p-0.5 text-white">
                                        <input
                                            type="checkbox"
                                            checked={cullIds.includes(p.id)}
                                            onChange={() => toggleCull(p.id)}
                                            className="h-3 w-3"
                                        />
                                    </label>
                                </div>
                            ))}
                        </div>
                    </section>

                    {/* ============ CENTER: selected photo / overview ============ */}
                    <section className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                        {selected ? (
                            <>
                                <div className="mb-3 flex items-center justify-between">
                                    <h3 className="text-sm font-semibold text-gray-800">
                                        {selected.filename}
                                        <span className="ms-2 text-xs font-normal text-gray-500">
                                            {selected.width && selected.height ? `${selected.width}×${selected.height}` : ''}
                                        </span>
                                    </h3>
                                    <div className="flex gap-2 text-xs">
                                        <span className={`rounded-full px-2 py-0.5 font-medium ${selected.selection_state === 'selected' ? 'bg-emerald-100 text-emerald-800' : selected.selection_state === 'culled' ? 'bg-rose-100 text-rose-800' : 'bg-gray-100 text-gray-600'}`}>
                                            {selected.selection_state}
                                        </span>
                                        <span className="rounded-full bg-indigo-100 px-2 py-0.5 font-medium text-indigo-700">{selected.retouch_state}</span>
                                    </div>
                                </div>
                                {selected.url ? (
                                    <img src={selected.url} alt={selected.filename} className="w-full rounded-lg border border-gray-200" />
                                ) : (
                                    <div className="flex h-64 items-center justify-center rounded-lg bg-gray-100 text-sm text-gray-500">No preview</div>
                                )}
                                <div className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-gray-600 sm:grid-cols-3">
                                    <span>Model: <b>{selected.camera_model ?? '—'}</b></span>
                                    <span>ISO: <b>{selected.iso ?? '—'}</b></span>
                                    <span>Lens: <b>{selected.lens ?? '—'}</b></span>
                                    <span>Aperture: <b>{selected.aperture ?? '—'}</b></span>
                                    <span>Shutter: <b>{selected.shutter_speed ?? '—'}</b></span>
                                    <span>Focal: <b>{selected.focal_length ?? '—'}</b></span>
                                </div>
                            </>
                        ) : (
                            <div className="flex h-64 items-center justify-center rounded-lg bg-gray-50 text-sm text-gray-500">
                                No photo selected.
                            </div>
                        )}

                        {/* Project overview */}
                        <div className="mt-5 border-t border-gray-100 pt-4">
                            <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-400">Project overview</h4>
                            <p className="mt-1 text-sm text-gray-600">{project.description ?? 'No description.'}</p>
                            <dl className="mt-3 grid grid-cols-2 gap-2 text-xs">
                                <div className="rounded-lg bg-gray-50 p-2"><dt className="text-gray-400">Owner</dt><dd className="font-medium text-gray-800">{project.owner ?? '—'}</dd></div>
                                <div className="rounded-lg bg-gray-50 p-2"><dt className="text-gray-400">Status</dt><dd className="font-medium text-gray-800">{project.status}</dd></div>
                                <div className="rounded-lg bg-gray-50 p-2"><dt className="text-gray-400">Photos</dt><dd className="font-medium text-gray-800">{photos.length}</dd></div>
                                <div className="rounded-lg bg-gray-50 p-2"><dt className="text-gray-400">Eligible for execute</dt><dd className="font-medium text-gray-800">{eligibleForExecution ? 'Yes' : 'No'}</dd></div>
                            </dl>
                        </div>
                    </section>

                    {/* ============ RIGHT: Creative Direction + Agent Proposal + Review ============ */}
                    <section className="space-y-5">
                        {/* Creative Direction */}
                        <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                            <h3 className="mb-2 text-sm font-semibold text-gray-800">Creative Direction</h3>
                            {brief ? (
                                <div className="space-y-2 text-xs text-gray-600">
                                    <p><b>Client:</b> {brief.client ?? '—'}</p>
                                    <p><b>Location:</b> {brief.location ?? '—'} · <b>Shoot:</b> {brief.shoot_date ?? '—'}</p>
                                    <p className="text-gray-700">{brief.creative_direction ?? '—'}</p>
                                    <p className="text-gray-500 italic">{brief.tonality_notes ?? ''}</p>
                                    <p className="text-gray-700"><b>Deliverables:</b> {brief.deliverables ?? '—'}</p>
                                </div>
                            ) : (
                                <p className="text-xs text-gray-500">No brief yet.</p>
                            )}
                        </div>

                        {/* Agent proposal controls */}
                        <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                            <h3 className="mb-2 text-sm font-semibold text-gray-800">Agent Proposal</h3>
                            <div className="space-y-2">
                                <button
                                    onClick={runProposeCull}
                                    disabled={busy !== null || !isAgent}
                                    className="w-full rounded-md bg-amber-600 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-500 disabled:opacity-40"
                                    title={!isAgent ? 'Log in as the agent to propose.' : 'Create a cull proposal (does not change selections)'}
                                >
                                    {busy === 'cull' ? '…' : `Propose Cull (${cullIds.length} selected)`}
                                </button>
                                <button
                                    onClick={runRetouchPlan}
                                    disabled={busy !== null || !isAgent}
                                    className="w-full rounded-md bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500 disabled:opacity-40"
                                >
                                    {busy === 'retouch' ? '…' : 'Propose Retouch Plan'}
                                </button>
                                <button
                                    onClick={runReview}
                                    disabled={busy !== null || !isAgent}
                                    className="w-full rounded-md bg-gray-600 px-3 py-2 text-xs font-semibold text-white hover:bg-gray-500 disabled:opacity-40"
                                >
                                    {busy === 'review' ? '…' : 'Run Consistency Review'}
                                </button>
                                {!isAgent && (
                                    <p className="text-[11px] text-gray-400">You are signed in as photographer.</p>
                                )}
                            </div>

                            {/* Pending proposals — review by photographer */}
                            <div className="mt-3 border-t border-gray-100 pt-3">
                                <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-400">Proposals</h4>
                                {localProposals.length === 0 ? (
                                    <p className="mt-2 text-xs text-gray-400">No proposals yet.</p>
                                ) : (
                                    <ul className="mt-2 space-y-2">
                                        {localProposals.map((p) => (
                                            <li key={p.id} className="rounded-lg border border-gray-200 p-2">
                                                <div className="flex items-center justify-between">
                                                    <span className="text-xs font-semibold text-gray-800">
                                                        #{p.id} {TYPE_LABEL[p.type] ?? p.type}
                                                    </span>
                                                    <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${p.status === 'approved' ? 'bg-emerald-100 text-emerald-700' : p.status === 'executed' ? 'bg-gray-800 text-white' : p.status === 'rejected' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700'}`}>
                                                        {STATE_LABEL[p.status] ?? p.status}
                                                    </span>
                                                </div>
                                                {p.summary && <p className="mt-1 text-[11px] text-gray-500">{p.summary}</p>}
                                                <div className="mt-1 text-[10px] text-gray-400">
                                                    {p.items.length} item(s) · {p.created_by ?? 'agent'} · {fmtTime(p.created_at)}
                                                </div>

                                                {/* Reviewer != agent: approve/reject/modify are HUMAN-ONLY */}
                                                {!isAgent && (p.status === 'pending_review' || p.status === 'draft') && (
                                                    <div className="mt-2 flex gap-2">
                                                        <button
                                                            onClick={() => humanApprove(p)}
                                                            disabled={busy !== null}
                                                            className="rounded bg-emerald-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-emerald-500 disabled:opacity-40"
                                                        >
                                                            Approve
                                                        </button>
                                                        <button
                                                            onClick={() => humanReject(p)}
                                                            disabled={busy !== null}
                                                            className="rounded bg-rose-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-rose-500 disabled:opacity-40"
                                                        >
                                                            Reject
                                                        </button>
                                                    </div>
                                                )}

                                                {/* Execute approved plan — visible to both, executes via registered tool */}
                                                {p.status === 'approved' && !p.executed_at && (
                                                    <button
                                                        onClick={runExecute}
                                                        disabled={busy !== null}
                                                        className="mt-2 w-full rounded bg-gray-800 px-2 py-1 text-[11px] font-semibold text-white hover:bg-gray-700 disabled:opacity-40"
                                                        title="Runs the dynamically-registered apply_approved_plan tool"
                                                    >
                                                        Execute Plan
                                                    </button>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </div>

                        {/* Agent / Tool Activity */}
                        <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                            <div className="mb-2 flex items-center justify-between">
                                <h3 className="text-sm font-semibold text-gray-800">Agent Activity</h3>
                                <button onClick={refreshState} disabled={busy !== null} className="text-[11px] font-medium text-gray-400 hover:text-gray-600">
                                    {busy === 'refresh' ? '…' : 'Refresh'}
                                </button>
                            </div>
                            <ul className="max-h-72 space-y-1.5 overflow-y-auto pr-1 text-xs">
                                {localActivity.length === 0 ? (
                                    <li className="text-gray-400">No agent activity yet.</li>
                                ) : (
                                    localActivity.map((a) => (
                                        <li key={a.id} className="flex items-start gap-2 rounded-md border border-gray-100 p-1.5">
                                            <span className={`mt-1 h-2 w-2 shrink-0 rounded-full ${STATUS_DOT[a.result_status] ?? 'bg-gray-400'}`} />
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-1.5">
                                                    <code className="rounded bg-gray-100 px-1 text-[11px] font-semibold text-gray-800">{a.tool_name}</code>
                                                    <span className={`rounded-full px-1.5 text-[9px] font-bold ${AUTHORITY_COLOR[a.authority] ?? 'bg-gray-100 text-gray-600'}`}>{a.authority}</span>
                                                </div>
                                                <div className="mt-0.5 text-[10px] text-gray-400">{fmtTime(a.created_at)} · {a.result_status}</div>
                                                {a.output_summary && (
                                                    <pre className="mt-1 max-w-full overflow-x-auto rounded bg-gray-50 p-1 text-[9px] leading-tight text-gray-500">{JSON.stringify(a.output_summary)}</pre>
                                                )}
                                            </div>
                                        </li>
                                    ))
                                )}
                            </ul>
                        </div>
                    </section>
                </div>

                {/* ============ BOTTOM: WebMCP diagnostics panel ============ */}
                <div className="mt-6 rounded-xl border border-gray-200 bg-white shadow-sm">
                    <button
                        onClick={() => setDiagOpen((o) => !o)}
                        className="flex w-full items-center justify-between px-4 py-3 text-left"
                    >
                        <span className="text-sm font-semibold text-gray-800">
                            WebMCP Diagnostics
                            {snapshot?.webmcpAvailable ? (
                                <span className="ms-2 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-700">document.modelContext active</span>
                            ) : (
                                <span className="ms-2 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-700">fallback (no WebMCP API)</span>
                            )}
                        </span>
                        <span className="text-gray-400">{diagOpen ? '▾' : '▸'}</span>
                    </button>
                    {diagOpen && (
                        <div className="border-t border-gray-100 px-4 py-3">
                            <div className="mb-2 flex flex-wrap gap-2 text-xs">
                                <span className="rounded bg-gray-100 px-2 py-1 text-gray-600">Project #{project.id}</span>
                                <span className={`rounded px-2 py-1 font-medium ${eligibleForExecution ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600'}`}>
                                    eligible_for_execution: {eligibleForExecution ? 'true' : 'false'}
                                </span>
                                <span className="rounded bg-gray-100 px-2 py-1 text-gray-600">
                                    using_fallback: {String(snapshot?.usingFallback ?? false)}
                                </span>
                            </div>
                            <table className="w-full text-left text-xs">
                                <thead>
                                    <tr className="border-b border-gray-100 text-[10px] uppercase tracking-wide text-gray-400">
                                        <th className="py-1.5 pr-2">Tool name</th>
                                        <th className="py-1.5 pr-2">Authority</th>
                                        <th className="py-1.5 pr-2">Dynamic</th>
                                        <th className="py-1.5 pr-2">Registered at</th>
                                        <th className="py-1.5">Live (API join)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {(snapshot?.registered ?? []).map((t) => (
                                        <tr key={t.name} className="border-b border-gray-50">
                                            <td className="py-1.5 pr-2 font-mono text-[11px] text-gray-800">{t.name}</td>
                                            <td className="py-1.5 pr-2">
                                                <span className={`rounded-full px-1.5 py-0.5 text-[9px] font-bold ${AUTHORITY_COLOR[t.authority] ?? ''}`}>{t.authority}</span>
                                            </td>
                                            <td className="py-1.5 pr-2 text-gray-500">{t.dynamic ? '●' : '—'}</td>
                                            <td className="py-1.5 pr-2 text-gray-400">{fmtTime(t.registeredAt)}</td>
                                            <td className="py-1.5 text-gray-500">{snapshot?.webmcpAvailable ? 'registered' : 'fallback only'}</td>
                                        </tr>
                                    ))}
                                    {snapshot && snapshot.registered.length === 0 && (
                                        <tr><td colSpan={5} className="py-2 text-gray-400">No tools registered (registry not started).</td></tr>
                                    )}
                                </tbody>
                            </table>
                            <div className="mt-2 text-[10px] text-gray-400">
                                apply_approved_plan is registered ONLY while an approved, unexecuted proposal exists — approve a proposal above to watch it appear, execute it to watch it disappear.
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
