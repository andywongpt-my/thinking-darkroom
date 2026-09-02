import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import AgentChatPanel from '@/Components/AgentChatPanel';
import DangerButton from '@/Components/DangerButton';
import Modal from '@/Components/Modal';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useWebmcpRegistry } from '@/webmcp/use-webmcp';
import { webmcpApi } from '@/webmcp/api';
import { autoDismissNotification } from '@/webmcp/notifications';
import type {
    AgentConversation,
    AgentPresence,
    CullingContext,
    CullingRecommendationEntry,
    PhotographerDecisionPayload,
    PhotoAnalysisResponse,
    PhotoSummary,
    ProposalItemPayload,
    ProposalPayload,
} from '@/webmcp/api';

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

/* ---------------- Sprint 4 — retouch / QA / creative memory types ---------------- */

interface RetouchCard {
    proposal_id: number;
    status: string;
    photo: { id: number; filename: string; width: number | null; height: number | null } | null;
    original: { url: string | null; sha256: string | null };
    derivative: {
        url: string | null;
        sha256: string | null;
        storage_path: string;
        adjustments: Record<string, number> | null;
        provenance: string;
        proposal_id: number | null;
    } | null;
    agent_original: {
        params: Record<string, number> | null;
        influenced_by: string[];
        brief_aware: boolean;
        note: string | null;
    };
    photographer_modification: { adjustments: Record<string, number> | null; note: string | null } | null;
    executed: { params: Record<string, number> | null; at: string | null } | null;
}

interface QaFindingRow {
    id: number;
    severity: string;
    category: string;
    message: string;
    photo_id: number | null;
    status: string;
    details: {
        observation?: string;
        expected?: string;
        explanation?: string;
        influenced_by?: string[];
        recommendation?: string;
    } | null;
}

interface CreativeMemoryRow {
    id: number;
    kind: string;
    lesson: string;
    /** Photographer display name — string from the page props; defensively
     * a {id,name} relation object is tolerated and projected via entityName
     * (the shape that caused React error #31 before the fix). */
    photographer: string | { id: number; name: string } | null;
    created_at: string | null;
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
        user: { id: number; name: string; is_agent: boolean; presence_eligible?: boolean };
    };
    presence?: AgentPresence;
    conversation?: AgentConversation;
    permissions?: {
        can_upload: boolean;
        can_photographer_act: boolean;
        can_execute: boolean;
        can_chat?: boolean;
    };
    webmcp: { available: boolean };
    initialCulling?: CullingContext | null;
    flash?: { success?: string };
    /** Sprint 4 — retouch truth card (three-layer history + real before/after). */
    retouchCard?: RetouchCard | null;
    /** Sprint 4 — persisted consistency-QA findings. */
    qaFindings?: QaFindingRow[];
    /** Sprint 4 — persisted photographer decision history (Creative Memory). */
    creativeMemories?: CreativeMemoryRow[];
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
    READ: 'bg-sky-500/15 text-sky-300',
    ANALYZE: 'bg-violet-500/15 text-violet-300',
    PROPOSE: 'bg-amber-400/10 text-amber-300',
    EXECUTE: 'bg-emerald-500/15 text-emerald-300',
};

const STATUS_DOT: Record<string, string> = {
    completed: 'bg-emerald-500',
    denied: 'bg-rose-500',
    failed: 'bg-rose-500',
    error: 'bg-rose-500',
};

const DECISION_BADGE: Record<string, string> = {
    keep: 'bg-emerald-500/15 text-emerald-300',
    review: 'bg-amber-400/10 text-amber-300',
    reject: 'bg-rose-500/15 text-rose-300',
};

function fmtTime(iso: string | null): string {
    if (!iso) return 'not recorded';
    return new Date(iso).toLocaleString();
}

/** The server supplies this eligibility bit from both agent boundaries. */
export function canHeartbeatPresence(user: { is_agent: boolean; presence_eligible?: boolean }): boolean {
    return user.is_agent === true && user.presence_eligible === true;
}

/** Use the write endpoint only for an eligible agent; everyone else reads. */
export function requestWorkspacePresence(
    projectId: number,
    user: { is_agent: boolean; presence_eligible?: boolean },
) {
    return canHeartbeatPresence(user)
        ? webmcpApi.heartbeatAgentPresence(projectId)
        : webmcpApi.getAgentPresence(projectId);
}

function latestAgentSeenAt(agents: AgentPresence['agents']): string | null {
    return agents.reduce<string | null>((latest, agent) => {
        if (!agent.last_seen_at) return latest;
        return latest === null || agent.last_seen_at > latest ? agent.last_seen_at : latest;
    }, null);
}

/** Preserve the server/network reason when an API response cannot be rendered. */
export function apiErrorReason(error: string | null, status: number): string {
    const message = error?.trim();
    if (message) return message;
    return status > 0 ? `request failed (HTTP ${status})` : 'request failed without a response';
}

/** Run a UI operation with a busy marker that always gets cleared. */
export async function withBusyState<T>(
    setBusy: (value: string | null) => void,
    value: string,
    operation: () => Promise<T>,
): Promise<T> {
    setBusy(value);

    try {
        return await operation();
    } finally {
        setBusy(null);
    }
}

/** Handle a rejected human-reject request without leaving the UI busy. */
export async function withHumanRejectErrorHandling<T>(
    setBusy: (value: string | null) => void,
    operation: () => Promise<T>,
    setNotify: (notification: { kind: 'ok' | 'err'; text: string }) => void,
): Promise<T | undefined> {
    try {
        return await withBusyState(setBusy, 'reject', operation);
    } catch {
        setNotify({ kind: 'err', text: 'Reject failed. Please try again.' });
        return undefined;
    }
}

/** Run a destructive workspace action only from an explicit confirmation. */
export function runAfterConfirmation<T>(
    target: T | null,
    confirmed: boolean,
    action: (target: T) => void,
): boolean {
    if (!confirmed || target === null) return false;
    action(target);
    return true;
}

/** Canonical project navigation into the photographer's Creative Room. */
export function CreativeRoomLink({ projectId }: { projectId: number }) {
    return (
        <Link
            href={route('creative.show', projectId)}
            data-testid="workspace-creative-room-link"
            className="rounded-md border border-emerald-500/40 px-3 py-1.5 text-xs font-semibold text-emerald-400 hover:bg-emerald-500/10"
        >
            Creative Room →
        </Link>
    );
}

export function PhotoDeleteDialog({
    show,
    photoName,
    processing,
    onClose,
    onConfirm,
}: {
    show: boolean;
    photoName: string;
    processing: boolean;
    onClose: () => void;
    onConfirm: () => void;
}) {
    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <div className="bg-zinc-900 p-6 text-zinc-100 sm:p-7">
                <p className="font-mono text-xs uppercase tracking-[0.2em] text-rose-300">IRREVERSIBLE CUT</p>
                <h2 id="delete-photo-title" className="mt-2 text-xl font-semibold text-zinc-100">
                    Delete photo?
                </h2>
                <p className="mt-3 text-sm leading-relaxed text-zinc-400">
                    Remove <span className="text-zinc-200">{photoName}</span> and its stored derivatives from the darkroom?
                </p>
                <p className="mt-3 text-xs leading-relaxed text-rose-300/80">
                    The original and any retouch derivatives are permanently deleted. This cannot be undone.
                </p>
                <div className="mt-6 flex flex-wrap justify-end gap-3 border-t border-zinc-800 pt-5">
                    <button
                        type="button"
                        data-testid="workspace-delete-photo-cancel"
                        onClick={onClose}
                        disabled={processing}
                        className="td-press rounded-lg border border-zinc-700 px-4 py-2 text-sm font-semibold text-zinc-300 transition hover:border-zinc-500 hover:text-zinc-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Keep photo
                    </button>
                    <DangerButton
                        type="button"
                        data-testid="workspace-delete-photo-confirm"
                        aria-label={`Permanently delete ${photoName}`}
                        onClick={onConfirm}
                        disabled={processing}
                    >
                        {processing ? (<><span className="td-spinner" aria-hidden="true" /> Deleting…</>) : 'Delete photo'}
                    </DangerButton>
                </div>
            </div>
        </Modal>
    );
}

export function WorkspaceConfirmDialog({
    show,
    title,
    description,
    processing,
    confirmTestId,
    onClose,
    onConfirm,
    eyebrow = 'IRREVERSIBLE CUT',
    cancelLabel = 'Keep',
    confirmLabel = 'Proceed',
}: {
    show: boolean;
    title: string;
    description: string;
    processing: boolean;
    confirmTestId: string;
    onClose: () => void;
    onConfirm: () => void;
    eyebrow?: string;
    cancelLabel?: string;
    confirmLabel?: string;
}) {
    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <div className="bg-zinc-900 p-6 text-zinc-100 sm:p-7">
                <p className="font-mono text-xs uppercase tracking-[0.2em] text-rose-300">{eyebrow}</p>
                <h2 className="mt-2 text-xl font-semibold text-zinc-100">{title}</h2>
                <p className="mt-3 text-sm leading-relaxed text-zinc-400">{description}</p>
                <div className="mt-6 flex flex-wrap justify-end gap-3 border-t border-zinc-800 pt-5">
                    <button
                        type="button"
                        data-testid={`${confirmTestId}-cancel`}
                        onClick={onClose}
                        disabled={processing}
                        className="td-press rounded-lg border border-zinc-700 px-4 py-2 text-sm font-semibold text-zinc-300 transition hover:border-zinc-500 hover:text-zinc-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {cancelLabel}
                    </button>
                    <DangerButton
                        type="button"
                        data-testid={confirmTestId}
                        aria-label={confirmLabel}
                        onClick={onConfirm}
                        disabled={processing}
                    >
                        {processing ? (<><span className="td-spinner" aria-hidden="true" /> Processing…</>) : confirmLabel}
                    </DangerButton>
                </div>
            </div>
        </Modal>
    );
}

/** Render the latest photographer decisions in the workspace side rail. */
export function DecisionLedger({ decisions }: { decisions: WorkspaceDecision[] }) {
    return (
        <div className="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 shadow-sm" data-testid="decision-history-panel">
            <div className="mb-2 flex items-center justify-between gap-2">
                <h3 className="text-sm font-semibold text-zinc-100">Decision ledger</h3>
                <span className="font-mono text-xs uppercase tracking-[0.16em] text-zinc-500">
                    {decisions.length} recorded
                </span>
            </div>
            {decisions.length === 0 ? (
                <p className="text-xs text-zinc-500">No photographer decisions recorded yet.</p>
            ) : (
                <ul className="space-y-2">
                    {decisions.map((entry) => {
                        const key = entry.decision.toLowerCase();
                        return (
                            <li key={entry.id} className="rounded-lg border border-zinc-800/70 p-2" data-testid={`decision-${entry.id}`}>
                                <div className="flex items-center justify-between gap-2">
                                    <div className="flex min-w-0 items-center gap-2">
                                        <span className={`rounded-full px-2 py-0.5 text-xs font-bold tracking-wide ${DECISION_BADGE[key] ?? 'bg-zinc-800 text-zinc-200'}`}>
                                            {entry.decision.toUpperCase()}
                                        </span>
                                        <span className="truncate text-xs text-zinc-300">{entry.photographer ?? 'photographer'}</span>
                                    </div>
                                    <time className="shrink-0 text-xs text-zinc-500" dateTime={entry.decided_at}>
                                        {fmtTime(entry.decided_at)}
                                    </time>
                                </div>
                                {entry.note && (
                                    <p className="mt-1.5 text-xs leading-relaxed text-zinc-400">“{entry.note}”</p>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}

export function mergeInspectedPhoto(
    photo: WorkspacePhoto,
    inspected?: Partial<WorkspacePhoto>,
): WorkspacePhoto {
    return { ...photo, ...(inspected ?? {}) };
}

function photoDimensions(photo: WorkspacePhoto): string {
    if (photo.dimensions) return photo.dimensions;
    if (photo.width !== null && photo.height !== null) return `${photo.width}×${photo.height}`;
    return '—';
}

/** Render EXIF fields from the server summary plus eager photo inspection. */
export function PhotoExifGrid({
    photo,
    inspected,
}: {
    photo: WorkspacePhoto;
    inspected?: Partial<WorkspacePhoto>;
}) {
    const details = mergeInspectedPhoto(photo, inspected);

    return (
        <div className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-zinc-300" data-testid="photo-exif-grid">
            <span>Model: <b>{details.camera_model ?? '—'}</b></span>
            <span>ISO: <b>{details.iso ?? '—'}</b></span>
            <span>Lens: <b>{details.lens ?? '—'}</b></span>
            <span>Aperture: <b>{details.aperture ?? '—'}</b></span>
            <span>Shutter: <b>{details.shutter_speed ?? '—'}</b></span>
            <span>Focal: <b>{details.focal_length ?? '—'}</b></span>
            <span>Dimensions: <b>{photoDimensions(details)}</b></span>
        </div>
    );
}

/**
 * The per-photo READ endpoint documents 409 as the normal pre-analysis state:
 * observations do not exist until the agent explicitly runs ANALYZE.
 */
export function isPhotoAnalysisRequired(status: number): boolean {
    return status === 409;
}

/** "+0.25 exposure · +0.08 warmth" style formatting for adjustment sets. */
export function fmtAdjustments(params: Record<string, number> | null | undefined): string {
    if (!params || Object.keys(params).length === 0) return '—';
    return Object.entries(params)
        .map(([k, v]) => `${(v ?? 0) >= 0 ? '+' : ''}${v} ${k}`)
        .join(' · ');
}

/**
 * Render-safe projection for a "named entity" field that may arrive as a
 * plain string OR as a relation object shaped {id, name} (e.g. an API
 * response that leaks the eager-loaded relation). The UI conceptually shows
 * a person name, so the object's `.name` is the human-readable value; any
 * other object shape falls back to a stable string so an object is NEVER
 * rendered as a React child (live E2E finding: Minified React error #31).
 */
export function entityName(value: unknown): string {
    if (value === null || value === undefined) return '';
    if (typeof value === 'string') return value;
    if (typeof value === 'object' && 'name' in (value as Record<string, unknown>)) {
        const name = (value as Record<string, unknown>).name;
        if (typeof name === 'string') return name;
    }
    return JSON.stringify(value);
}

/** Numeric-only projection of an adjustment payload (drops string metadata). */
function numericAdjustments(params: Record<string, unknown> | null | undefined): Record<string, number> {
    if (!params) return {};
    const out: Record<string, number> = {};
    for (const [k, v] of Object.entries(params)) {
        if (typeof v === 'number' && Number.isFinite(v)) {
            // Skip enrichment metadata that happens to be numeric (counts).
            if (k === 'brief_aware') continue;
            out[k] = v;
        }
    }
    return out;
}

function proposalPhotoCount(proposal: WorkspaceProposal): number {
    return new Set(proposal.items.map((item) => item.photo_id)).size;
}

/* ---------------- Sprint 3 — context-aware culling constants ---------------- */

/** Recommendation → { label, tone }. Order encodes strength for comparisons. */
export const RECOMMENDATION_META: Record<
    string,
    { label: string; badge: string; rank: number }
> = {
    strong_keep: { label: 'STRONG KEEP', badge: 'bg-emerald-500 text-zinc-950', rank: 3 },
    keep: { label: 'KEEP', badge: 'bg-emerald-500/15 text-emerald-300', rank: 2 },
    review: { label: 'REVIEW', badge: 'bg-amber-400/10 text-amber-300', rank: 1 },
    reject_candidate: { label: 'REJECT CANDIDATE', badge: 'bg-rose-500 text-zinc-950', rank: 0 },
};

export const recommendationRank = (r: string): number => RECOMMENDATION_META[r]?.rank ?? 1;

/** Honest, human presentation of observation provenance (never pixel claims for creative data). */
export const PROVENANCE_LABEL: Record<string, string> = {
    pixel_analysis: 'Technical analysis — pixel-derived',
    demo_sidecar_annotation: 'Creative context — demo annotation',
};

export const provenanceLabel = (key: string): string =>
    PROVENANCE_LABEL[key] ?? key;

/** Photographer-facing word for a technical assessment value. */
export const assessmentText = (a: { assessment: string; confidence: number } | null | undefined): string =>
    !a ? '—' : a.assessment.replace(/_/g, ' ');

/** "90%" from a 0..1 confidence. */
export const confidencePct = (c: number): string => `${Math.round(c * 100)}%`;

/**
 * Splits the tradeoff text around the adopted-brief reference so the WHY
 * section can bold the brief linkage without hard-coding a photo id.
 */
export function tradeoffParts(tradeoff: string | null | undefined): { before: string; brief: string } {
    const t = tradeoff ?? '';
    const marker = ' the adopted ';
    const idx = t.indexOf(marker);
    if (idx === -1) return { before: t, brief: '' };
    // before keeps everything up to (but excluding) the marker; the bolded
    // brief segment starts AT the marker text ("the adopted …") so the two
    // halves re-join to the original sentence with a single space.
    return { before: t.slice(0, idx).trimEnd(), brief: t.slice(idx + 1) };
}

/** Culling decision the photographer may record. */
export type CullingChoice = 'keep' | 'review' | 'reject';

export default function Workspace({
    initialCulling: initialCullingProp,
    initialAnalysis = null,
}: {
    /** Server-rendered culling context (avoids a fetch round-trip on load). */
    initialCulling?: CullingContext | null;
    /** Server-rendered deep analysis for the initially-selected photo. */
    initialAnalysis?: PhotoAnalysisResponse | null;
} = {}) {
    const page = usePage<PageProps>();
    const { project, brief, photos, proposals, decisions, activity, request, permissions: pagePermissions, flash, initialCulling: pageInitialCulling, retouchCard: pageRetouchCard, qaFindings: pageQaFindings, creativeMemories: pageCreativeMemories, presence: pagePresence, conversation: pageConversation } = page.props;

    const isAgent = request.user.is_agent;
    const heartbeatEligible = canHeartbeatPresence(request.user);
    const permissions = pagePermissions ?? {
        can_upload: false,
        can_photographer_act: false,
        can_execute: false,
        can_chat: false,
    };
    const conversation = pageConversation ?? {
        project_id: project.id,
        trust_boundary: 'untrusted_project_conversation' as const,
        messages: [],
        latest_id: null,
        has_older: false,
    };
    const canPhotographerAct = permissions.can_photographer_act;

    const [agentPresence, setAgentPresence] = useState<AgentPresence>(() => pagePresence ?? {
        project_id: project.id,
        online: false,
        agents: [],
        checked_at: '',
    });
    const lastActiveAt = latestAgentSeenAt(agentPresence.agents);
    const activeAgentNames = agentPresence.agents
        .filter((agent) => agent.status === 'online')
        .map((agent) => agent.name)
        .join(', ');

    const [selectedId, setSelectedId] = useState<number | null>(photos[0]?.id ?? null);
    const [deletePhotoId, setDeletePhotoId] = useState<number | null>(null);
    const [deletingPhoto, setDeletingPhoto] = useState(false);
    const [rejectTargetId, setRejectTargetId] = useState<number | null>(null);
    const [executeTargetId, setExecuteTargetId] = useState<number | null>(null);
    const [dismissQaTargetId, setDismissQaTargetId] = useState<number | null>(null);
    const [localProposals, setLocalProposals] = useState<WorkspaceProposal[]>(proposals);
    const [localActivity, setLocalActivity] = useState<ActivityEntry[]>(activity);
    const [references, setReferences] = useState<unknown[]>([]);
    const [cullIds, setCullIds] = useState<number[]>([]);
    const [busy, setBusy] = useState<string | null>(null);
    const [diagOpen, setDiagOpen] = useState(false);

    /* ---------------- Sprint 4 — retouch / QA / creative memory state ---------------- */

    // Retouch truth card (server-rendered three-layer history).
    const retouchCard = pageRetouchCard ?? null;
    const retouchRenderFailed = Boolean(retouchCard?.executed && !retouchCard.derivative);
    const [notify, setNotify] = useState<{ kind: 'ok' | 'err'; text: string } | null>(
        retouchRenderFailed
            ? { kind: 'err', text: 'Retouch render failed: no approved derivative was stored.' }
            : null,
    );
    const notifyTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const uploadRef = useRef<HTMLInputElement>(null);
    // Persisted QA findings, locally updated after photographer QA actions.
    const [qaFindings, setQaFindings] = useState<QaFindingRow[]>(pageQaFindings ?? []);
    // Persisted Creative Memory lessons (photographer decision history).
    const [memories, setMemories] = useState<CreativeMemoryRow[]>(pageCreativeMemories ?? []);
    // Which retouch proposal's modify form is open.
    const [modifyOpenFor, setModifyOpenFor] = useState<number | null>(null);

    useEffect(() => {
        setLocalProposals(proposals);
    }, [proposals]);

    useEffect(() => {
        setLocalActivity(activity);
    }, [activity]);

    useEffect(() => {
        setQaFindings(pageQaFindings ?? []);
    }, [pageQaFindings]);

    useEffect(() => {
        setMemories(pageCreativeMemories ?? []);
    }, [pageCreativeMemories]);

    useEffect(() => autoDismissNotification(notify, busy, setNotify, notifyTimer), [notify, busy]);

    /** Agent's original numeric adjustments for a retouch proposal. */
    const agentValuesFor = (p: WorkspaceProposal): Record<string, number> =>
        numericAdjustments(p.items[0]?.params ?? null);

    /**
     * Photographer-modified values for a proposal chain: the retouchCard's
     * modification layer (server-truth) matched to this chain, else null.
     */
    const photographerValuesFor = (p: WorkspaceProposal): Record<string, number> | null =>
        retouchCard?.photographer_modification?.adjustments ?? null;
    // Photographer-edited adjustment values for the pending retouch proposal.
    const [modifyValues, setModifyValues] = useState<{ exposure: string; warmth: string }>({ exposure: '', warmth: '' });
    // Draft text for a new Creative Memory lesson.
    const [memoryDraft, setMemoryDraft] = useState('');

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
    const deleteTarget = photos.find((p) => p.id === deletePhotoId) ?? null;
    const rejectTarget = localProposals.find((p) => p.id === rejectTargetId) ?? null;
    const executeTarget = localProposals.find((p) => p.id === executeTargetId) ?? null;
    const dismissQaTarget = qaFindings.find((f) => f.id === dismissQaTargetId) ?? null;

    const closeDeletePhoto = () => {
        if (!deletingPhoto) {
            setDeletePhotoId(null);
        }
    };

    const confirmDeletePhoto = () => {
        const targetId = deletePhotoId;
        if (targetId === null) {
            return;
        }

        setDeletingPhoto(true);
        router.delete(route('workspace.photos.destroy', [project.id, targetId]), {
            preserveScroll: true,
            onSuccess: () => {
                setDeletePhotoId(null);
                setSelectedId((current) => (current === targetId ? null : current));
            },
            onError: () => {
                setNotify({ kind: 'err', text: 'Photo deletion failed.' });
            },
            onFinish: () => setDeletingPhoto(false),
        });
    };

    // Presence is operational state: eligible agents heartbeat, everyone else reads.
    // Failed refreshes intentionally leave the last server-confirmed state intact.
    useEffect(() => {
        let live = true;

        const syncPresence = async (): Promise<void> => {
            try {
                const response = await requestWorkspacePresence(project.id, request.user);

                if (live && response.ok && response.data) {
                    setAgentPresence(response.data);
                }
            } catch {
                // Keep the last known server state when a refresh cannot complete.
            }
        };

        void syncPresence();
        const interval = window.setInterval(() => {
            void syncPresence();
        }, 30_000);

        return () => {
            live = false;
            window.clearInterval(interval);
        };
    }, [project.id, heartbeatEligible]);

    /* ---------------- Sprint 3 — context-aware culling state ---------------- */

    // Project-wide culling picture (observations + recommendations).
    // Inertia page props (server-rendered) win; direct component props are
    // the test seam.
    const [culling, setCulling] = useState<CullingContext | null>(pageInitialCulling ?? initialCullingProp ?? null);
    const [cullingLoading, setCullingLoading] = useState((pageInitialCulling ?? initialCullingProp) === null);
    const [cullingError, setCullingError] = useState<string | null>(null);
    // Per-photo analysis for the selected frame (deep payload incl. provenance).
    const [analysis, setAnalysis] = useState<PhotoAnalysisResponse | null>(initialAnalysis);
    const [analysisError, setAnalysisError] = useState<string | null>(null);
    const [analysisRefresh, setAnalysisRefresh] = useState(0);
    // photographer decisions recorded this session (photo_id → decision payload).
    const [myDecisions, setMyDecisions] = useState<Record<number, PhotographerDecisionPayload['decision']>>({});
    const [overrideNote, setOverrideNote] = useState('');
    const [overrideOpen, setOverrideOpen] = useState(false);

    const recFor = useMemo(() => {
        const map = new Map<number, CullingRecommendationEntry>();
        (culling?.recommendations ?? []).forEach((r) => map.set(r.photo.id, r));
        return map;
    }, [culling]);

    const selectedRec = selected ? recFor.get(selected.id) ?? null : null;

    // Load the project-wide culling context once per project (and after overrides).
    const loadCulling = async () => {
        setCullingLoading(true);
        setCullingError(null);
        try {
            const res = await webmcpApi.getCullingContext(project.id);
            if (res.ok && res.data) {
                setCulling(res.data);
                return;
            }
            const reason = apiErrorReason(res.error, res.status);
            setCullingError(reason);
            setNotify({ kind: 'err', text: `Culling context failed: ${reason}` });
        } catch (error) {
            const reason = error instanceof Error ? error.message : String(error);
            setCullingError(reason);
            setNotify({ kind: 'err', text: `Culling context failed: ${reason}` });
        } finally {
            setCullingLoading(false);
        }
    };

    useEffect(() => {
        void loadCulling();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [project.id]);

    // Deep per-photo analysis for the selected frame.
    useEffect(() => {
        if (!selected) {
            setAnalysis(null);
            setAnalysisError(null);
            return;
        }
        let live = true;
        setAnalysisError(null);
        webmcpApi.getPhotoAnalysis(project.id, selected.id)
            .then((res) => {
                if (!live) return;
                if (res.ok && res.data) {
                    setAnalysis(res.data);
                    return;
                }
                if (isPhotoAnalysisRequired(res.status)) {
                    // 409 is the documented, non-error state before the
                    // agent has persisted observations with ANALYZE.
                    setAnalysis(null);
                    return;
                }
                const reason = apiErrorReason(res.error, res.status);
                setAnalysis(null);
                setAnalysisError(reason);
                setNotify({ kind: 'err', text: `Photo analysis failed: ${reason}` });
            })
            .catch((error: unknown) => {
                if (!live) return;
                const reason = error instanceof Error ? error.message : String(error);
                setAnalysis(null);
                setAnalysisError(reason);
                setNotify({ kind: 'err', text: `Photo analysis failed: ${reason}` });
            });
        return () => {
            live = false;
        };
    }, [project.id, selectedId, analysisRefresh]);

    /**
     * HUMAN-ONLY photographer decision (never a WebMCP tool). Persists
     * through photographer_decisions and updates selection_state.
     */
    const recordCullingDecision = async (
        photoId: number,
        decision: CullingChoice,
        note?: string,
        override?: boolean,
    ): Promise<PhotographerDecisionPayload | null> => {
        const res = await webmcpApi.photographerCullingDecide(project.id, photoId, decision, note, override);
        if (res.ok && res.data) {
            setMyDecisions((d) => ({ ...d, [photoId]: res.data!.decision }));
            addActivity({
                tool_name: 'photographer_culling_decide',
                authority: 'HUMAN',
                result_status: 'completed',
                output_summary: {
                    photo_id: photoId,
                    decision,
                    override: res.data.decision.override,
                    note: res.data.decision.note ?? undefined,
                },
            });
            setNotify({ kind: 'ok', text: `Decision recorded: ${decision.toUpperCase()}${res.data.decision.override ? ' (override)' : ''}.` });
            return res.data;
        }
        setNotify({ kind: 'err', text: `Decision failed: ${res.error}` });
        return null;
    };

    const refreshState = async () => {
        setBusy('refresh');
        try {
            const [ctx, ph] = await Promise.all([
                webmcpApi.getWorkspaceContext(project.id),
                webmcpApi.listProjectPhotos(project.id),
            ]);
            const failures = [
                !ctx.ok || !ctx.data ? `workspace context: ${apiErrorReason(ctx.error, ctx.status)}` : null,
                !ph.ok || !ph.data ? `photos: ${apiErrorReason(ph.error, ph.status)}` : null,
            ].filter((failure): failure is string => failure !== null);
            if (failures.length > 0) {
                setNotify({ kind: 'err', text: `Workspace refresh failed: ${failures.join('; ')}` });
                return;
            }

            // merge any fresh inspection fields (keep selection_state in sync)
            setSelectedId((cur) => (ph.data!.photos.some((p) => p.id === cur) ? cur : ph.data!.photos[0]?.id ?? null));
            setNotify({ kind: 'ok', text: `Workspace refreshed: ${ctx.data!.counts.total} photos.` });
            router.reload({ only: ['photos', 'proposals', 'retouchCard', 'activity', 'decisions', 'qaFindings', 'creativeMemories'] });
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

    const runAnalyze = async () => {
        setBusy('analyze');
        try {
            const res = await webmcpApi.analyzeProjectPhotos(project.id);
            if (res.ok && res.data) {
                addActivity({
                    tool_name: 'analyze_project_photos',
                    authority: 'ANALYZE',
                    result_status: 'completed',
                    output_summary: {
                        newly_analyzed: res.data.newly_analyzed,
                        refreshed_observations: res.data.refreshed_observations,
                        total_observed: res.data.total_observed,
                    },
                });
                await loadCulling();
                setAnalysisRefresh((version) => version + 1);
                setNotify({
                    kind: 'ok',
                    text: res.data.refreshed_observations > 0
                        ? `Analysis refreshed ${res.data.refreshed_observations} prior unavailable observation(s)${res.data.newly_analyzed > 0 ? ` and created ${res.data.newly_analyzed} new observation(s)` : ''}.`
                        : `Analysis complete: ${res.data.newly_analyzed} new observation(s).`,
                });
            } else {
                addActivity({
                    tool_name: 'analyze_project_photos',
                    authority: 'ANALYZE',
                    result_status: 'error',
                    output_summary: { error: res.error },
                });
                setNotify({ kind: 'err', text: `analyze_project_photos failed: ${res.error}` });
            }
        } finally {
            setBusy(null);
        }
    };

    const runReview = async () => {
        setBusy('review');
        const res = await webmcpApi.runConsistencyReview(project.id, 'selected');
        setBusy(null);
        if (res.ok && res.data) {
            addActivity({ tool_name: 'run_consistency_review', authority: 'ANALYZE', result_status: 'completed', output_summary: { findings: res.data.created_findings.length } });
            setNotify({ kind: 'ok', text: `Consistency review done: ${res.data.created_findings.length} finding(s).` });
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
            if (resp.status === 419) {
                throw new Error('CSRF token mismatch (419) — reload the page and retry.');
            }
            if (resp.ok && j?.proposal) {
                setLocalProposals((ps) => ps.map((p) => (p.id === proposal.id ? { ...p, status: 'approved' } : p)));
                addActivity({ tool_name: 'photographer_approve', authority: 'EXECUTE', result_status: 'completed', output_summary: { proposal_id: proposal.id, by: request.user.name } });
                setNotify({ kind: 'ok', text: `Proposal #${proposal.id} approved. apply_approved_plan is now registered.` });
            } else {
                setNotify({ kind: 'err', text: `Approval failed: ${j?.error ?? resp.statusText}` });
            }
        } finally {
            setBusy(null);
        }
    };

    const humanReject = async (proposal: WorkspaceProposal) => {
        const result = await withHumanRejectErrorHandling(setBusy, async () => {
            const resp = await fetch(route('proposals.reject', [project.id, proposal.id]), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '' },
            });
            const j = await resp.json().catch(() => null);

            return { resp, j };
        }, setNotify);
        if (!result) return;

        const { resp, j } = result;
        if (resp.ok) {
            setLocalProposals((ps) => ps.map((p) => (p.id === proposal.id ? { ...p, status: 'rejected' } : p)));
            addActivity({ tool_name: 'photographer_reject', authority: 'READ', result_status: 'completed', output_summary: { proposal_id: proposal.id, by: request.user.name } });
            setNotify({ kind: 'ok', text: `Proposal #${proposal.id} rejected.` });
            setRejectTargetId(null);
        } else {
            setNotify({ kind: 'err', text: `Reject failed: ${j?.error ?? resp.statusText}` });
        }
    };

    const runExecute = async (target: WorkspaceProposal) => {
        // Execute the proposal the photographer CLICKED (Sol P1-9), not
        // whichever proposal happens to be first-eligible in state.
        if (!target || target.status !== 'approved' || target.executed_at) {
            setNotify({ kind: 'err', text: 'That proposal is no longer eligible for execution.' });
            setExecuteTargetId(null);
            return;
        }
        setBusy('execute');
        const res = await webmcpApi.applyApprovedPlan(project.id, target.id);
        setBusy(null);
        if (res.ok && res.data) {
            setLocalProposals((ps) => ps.map((p) => (p.id === target.id ? { ...p, status: 'executed', executed_at: new Date().toISOString() } : p)));
            addActivity({ tool_name: 'apply_approved_plan', authority: 'EXECUTE', result_status: 'completed', output_summary: { proposal_id: target.id } });
            registry?.markExecuted();
            // Honest partial/failure accounting from the backend execution summary.
            const execution = (res.data as { payload?: { execution?: { items_applied?: number; items_failed?: number; items_skipped?: number; items_attempted?: number } } }).payload?.execution;
            const partial = execution && ((execution.items_failed ?? 0) > 0 || (execution.items_skipped ?? 0) > 0);
            if (partial) {
                setNotify({ kind: 'err', text: `Proposal #${target.id} executed with issues: applied ${execution?.items_applied ?? 0}/${execution?.items_attempted ?? 0}, ${execution?.items_failed ?? 0} failed, ${execution?.items_skipped ?? 0} skipped.` });
            } else {
                setNotify({ kind: 'ok', text: `Proposal #${target.id} executed. apply_approved_plan removed.` });
            }
            setExecuteTargetId(null);
            // refresh photo state from server
            webmcpApi.listProjectPhotos(project.id).then((r) => {
                if (r.ok && r.data) {
                    router.reload({ only: ['photos', 'proposals', 'retouchCard', 'activity', 'decisions', 'qaFindings', 'creativeMemories'] });
                }
            });
        } else {
            addActivity({ tool_name: 'apply_approved_plan', authority: 'EXECUTE', result_status: 'error', output_summary: { error: res.error, proposal_id: target.id } });
            setNotify({ kind: 'err', text: `execute failed: ${res.error}` });
        }
    };

    /* ---------------- Sprint 4 — human authority handlers (never WebMCP tools) ---------------- */

    /** HUMAN-ONLY: photographer edits adjustment VALUES, superseding proposal lands pending_review. */
    const humanModify = async (proposal: WorkspaceProposal) => {
        const adjustments: Record<string, number> = {};
        const exposure = parseFloat(modifyValues.exposure);
        const warmth = parseFloat(modifyValues.warmth);
        if (Number.isFinite(exposure)) adjustments.exposure = exposure;
        if (Number.isFinite(warmth)) adjustments.warmth = warmth;
        if (Object.keys(adjustments).length === 0) {
            setNotify({ kind: 'err', text: 'Enter at least one adjustment value to modify.' });
            return;
        }
        setBusy('modify');
        try {
            const resp = await fetch(route('proposals.modify', [project.id, proposal.id]), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '',
                },
                body: JSON.stringify({
                    note: 'Photographer edited the adjustment values.',
                    modifications: { adjustments },
                }),
            });
            const j = await resp.json().catch(() => null);
            if (resp.ok && j?.superseding_draft) {
                setLocalProposals((ps) => [
                    normalizeProposal(j.superseding_draft),
                    ...ps.map((p) => (p.id === proposal.id ? { ...p, status: 'modified' } : p)),
                ]);
                addActivity({ tool_name: 'photographer_modify', authority: 'HUMAN', result_status: 'completed', output_summary: { proposal_id: proposal.id, superseded_by: j.superseding_draft.id, adjustments } });
                setNotify({ kind: 'ok', text: `Values saved. New proposal #${j.superseding_draft.id} is pending your review.` });
                setModifyValues({ exposure: '', warmth: '' });
            } else {
                setNotify({ kind: 'err', text: `Modify failed: ${j?.error ?? resp.statusText}` });
            }
        } finally {
            setBusy(null);
        }
    };

    /** HUMAN-ONLY: acknowledge / dismiss a QA finding. */
    const respondQaFinding = async (finding: QaFindingRow, action: 'acknowledge' | 'dismiss') => {
        setBusy(`qa-${finding.id}`);
        try {
            const resp = await fetch(route('qa-findings.respond', [project.id, finding.id]), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '',
                },
                body: JSON.stringify({ action }),
            });
            const j = await resp.json().catch(() => null);
            if (resp.ok && j?.finding) {
                setQaFindings((fs) => fs.map((f) => (f.id === finding.id ? { ...f, status: j.finding.status } : f)));
                addActivity({ tool_name: `photographer_qa_${action}`, authority: 'HUMAN', result_status: 'completed', output_summary: { finding_id: finding.id, status: j.finding.status } });
                setNotify({ kind: 'ok', text: `Finding #${finding.id} ${action}d.` });
                if (action === 'dismiss') setDismissQaTargetId(null);
            } else {
                setNotify({ kind: 'err', text: `QA action failed: ${j?.error ?? resp.statusText}` });
            }
        } finally {
            setBusy(null);
        }
    };

    const confirmReject = () => {
        const target = localProposals.find((proposal) => proposal.id === rejectTargetId) ?? null;
        if (!runAfterConfirmation(target, true, (proposal) => { void humanReject(proposal); })) {
            setRejectTargetId(null);
        }
    };

    const confirmExecute = () => {
        const target = localProposals.find((proposal) => proposal.id === executeTargetId) ?? null;
        if (!runAfterConfirmation(target, true, (proposal) => { void runExecute(proposal); })) {
            setExecuteTargetId(null);
        }
    };

    const confirmQaDismiss = () => {
        const target = qaFindings.find((finding) => finding.id === dismissQaTargetId) ?? null;
        if (!runAfterConfirmation(target, true, (finding) => { void respondQaFinding(finding, 'dismiss'); })) {
            setDismissQaTargetId(null);
        }
    };

    /** HUMAN-ONLY: persist a photographer-authored creative memory lesson. */
    const storeMemory = async (lesson: string) => {
        const text = lesson.trim();
        if (text.length < 3) {
            setNotify({ kind: 'err', text: 'A lesson needs at least 3 characters.' });
            return;
        }
        setBusy('memory');
        try {
            const resp = await fetch(route('creative-memory.store', project.id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '',
                },
                body: JSON.stringify({ lesson: text }),
            });
            const j = await resp.json().catch(() => null);
            if (resp.ok && j?.memory) {
                setMemories((ms) => [j.memory, ...ms]);
                addActivity({ tool_name: 'photographer_store_memory', authority: 'HUMAN', result_status: 'completed', output_summary: { memory_id: j.memory.id } });
                setNotify({ kind: 'ok', text: 'Lesson saved to Creative Memory.' });
            } else {
                setNotify({ kind: 'err', text: `Save failed: ${j?.error ?? resp.statusText}` });
            }
        } finally {
            setBusy(null);
        }
    };

    const doUpload = (files: FileList | null) => {
        if (!files || files.length === 0) return;
        // Client-side contract guard: Vercel hard-caps request bodies at 4.5MB,
        // so oversized selections must be rejected before the edge does (Sol P1).
        const MAX_PER_FILE = 4.3 * 1024 * 1024;
        const MAX_COUNT = 10;
        const oversized = Array.from(files).filter((f) => f.size > MAX_PER_FILE);
        if (oversized.length > 0) {
            setNotify({ kind: 'err', text: `Upload failed: ${oversized[0].name} is ${(oversized[0].size / 1024 / 1024).toFixed(1)}MB — each file must be under 4.3MB on this deployment.` });
            return;
        }
        if (files.length > MAX_COUNT) {
            setNotify({ kind: 'err', text: `Upload failed: up to ${MAX_COUNT} photos per batch.` });
            return;
        }
        const form = new FormData();
        Array.from(files).forEach((f) => form.append('photos[]', f));
        setBusy('upload');
        router.post(route('workspace.upload', project.id), form, {
            forceFormData: true,
            preserveScroll: true,
            // Success copy is owned by the backend flash (set only after the
            // storage write AND DB row are verified). onSuccess must not
            // claim success on its own: Inertia also resolves onSuccess for
            // redirect responses that carry flash errors (truthful-state fix,
            // 2026-08-29 audit P1-1).
            onSuccess: () => {
                setBusy(null);
                router.reload({ only: ['photos', 'proposals', 'retouchCard', 'activity', 'decisions', 'qaFindings', 'creativeMemories'] });
            },
            onError: (errors) => {
                setBusy(null);
                const first = errors && typeof errors === 'object'
                    ? (Object.values(errors).find((v): v is string => typeof v === 'string') ?? null)
                    : null;
                setNotify({ kind: 'err', text: first ? `Upload failed: ${first}` : 'Upload failed.' });
            },
        });
    };

    const toggleCull = (id: number) => {
        setCullIds((c) => (c.includes(id) ? c.filter((x) => x !== id) : [...c, id]));
    };

    const webmcpUnavailable = !(snapshot?.webmcpAvailable ?? false) && !(snapshot?.usingFallback ?? false);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between gap-4">
                    <div className="flex min-w-0 items-center gap-3">
                        <Link
                            href={route('dashboard')}
                            data-testid="workspace-all-projects-link"
                            className="shrink-0 text-xs font-semibold text-zinc-400 transition hover:text-amber-300"
                        >
                            ← All projects
                        </Link>
                        <h2 className="truncate text-xl font-semibold leading-tight text-zinc-100">{project.name}</h2>
                    </div>
                    <CreativeRoomLink projectId={project.id} />
                </div>
            }
        >
            <Head title={project.name} />

            <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <div
                    role="status"
                    aria-live="polite"
                    data-testid="agent-presence-strip"
                    data-status={agentPresence.online ? 'online' : 'offline'}
                    className={`td-slide-down mb-4 flex items-start gap-3 rounded-lg border px-4 py-3 ${agentPresence.online ? 'border-emerald-500/30 bg-emerald-500/10' : 'border-zinc-800 bg-zinc-950/40'}`}
                >
                    <span
                        aria-hidden="true"
                        data-testid="agent-presence-dot"
                        className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${agentPresence.online ? 'td-live-dot bg-emerald-500' : 'bg-zinc-600'}`}
                    />
                    <div>
                        <p className={`text-sm font-semibold ${agentPresence.online ? 'text-emerald-300' : 'text-zinc-200'}`}>
                            {agentPresence.online
                                ? 'Agent online · active in this workspace'
                                : 'Agent offline · waiting for an agent'}
                        </p>
                        {agentPresence.online && activeAgentNames && (
                            <p className="mt-0.5 text-xs text-emerald-400">Active agent: {activeAgentNames}</p>
                        )}
                        {!agentPresence.online && lastActiveAt && (
                            <p className="mt-0.5 text-xs text-zinc-500">Last active {fmtTime(lastActiveAt)}</p>
                        )}
                    </div>
                </div>

                {/* WebMCP availability banner */}
                {webmcpUnavailable && (
                    <div className="mb-4 rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-400">
                        <strong>WebMCP is not available in this browser.</strong> The app loads normally, but
                        agent tools will not be registered on <code>document.modelContext</code>.
                    </div>
                )}
                {flash?.success && (
                    <div className="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-400">{flash.success}</div>
                )}
                {notify && (
                    <div role="status" aria-live="polite" data-testid="workspace-notify" className={`td-slide-down mb-4 flex items-start justify-between gap-3 rounded-lg border px-4 py-3 text-sm ${notify.kind === 'ok' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400' : 'border-rose-500/30 bg-rose-500/10 text-rose-400'}`}>
                        <span className="flex items-start gap-2.5">
                            <span aria-hidden="true" className={`mt-0.5 inline-block h-2 w-2 shrink-0 rounded-full ${notify.kind === 'ok' ? 'bg-emerald-400' : 'bg-rose-400'}`} />
                            {notify.text}
                        </span>
                        <button
                            type="button"
                            aria-label="Dismiss notification"
                            onClick={() => setNotify(null)}
                            className="shrink-0 rounded px-1 text-lg leading-none text-current/70 transition hover:text-current focus:outline-none focus-visible:ring-2 focus-visible:ring-current"
                        >
                            ×
                        </button>
                    </div>
                )}

                <AgentChatPanel
                    projectId={project.id}
                    currentUser={request.user}
                    canSend={permissions.can_chat ?? false}
                    initialConversation={conversation}
                    presence={agentPresence}
                />

                <WorkspaceConfirmDialog
                    show={rejectTarget !== null}
                    title="Reject proposal?"
                    description={rejectTarget ? `Reject proposal #${rejectTarget.id}? The proposal remains in the decision history and will not be executed.` : ''}
                    processing={busy === 'reject'}
                    confirmTestId="workspace-confirm-reject"
                    eyebrow="PROPOSAL GATE"
                    cancelLabel="Keep proposal"
                    onClose={() => {
                        if (busy !== 'reject') setRejectTargetId(null);
                    }}
                    onConfirm={confirmReject}
                />

                <PhotoDeleteDialog
                    show={deletePhotoId !== null}
                    photoName={deleteTarget?.filename ?? 'this photo'}
                    processing={deletingPhoto}
                    onClose={closeDeletePhoto}
                    onConfirm={confirmDeletePhoto}
                />

                <WorkspaceConfirmDialog
                    show={executeTarget !== null}
                    title="Execute approved plan?"
                    description={executeTarget ? `Apply ${executeTarget.items.length} operation(s) to ${proposalPhotoCount(executeTarget)} photo(s) in the darkroom? This cannot be undone.` : ''}
                    processing={busy === 'execute'}
                    confirmTestId="workspace-confirm-execute"
                    eyebrow="IRREVERSIBLE CUT"
                    cancelLabel="Keep plan"
                    onClose={() => {
                        if (busy !== 'execute') setExecuteTargetId(null);
                    }}
                    onConfirm={confirmExecute}
                />

                <WorkspaceConfirmDialog
                    show={dismissQaTarget !== null}
                    title="Dismiss QA finding?"
                    description={dismissQaTarget ? `Dismiss finding #${dismissQaTarget.id}? Its history remains recorded while it leaves the open queue.` : ''}
                    processing={dismissQaTarget !== null && busy === `qa-${dismissQaTarget.id}`}
                    confirmTestId="workspace-confirm-dismiss-qa"
                    eyebrow="QA REVIEW GATE"
                    cancelLabel="Keep finding"
                    onClose={() => {
                        if (!dismissQaTarget || busy !== `qa-${dismissQaTarget.id}`) setDismissQaTargetId(null);
                    }}
                    onConfirm={confirmQaDismiss}
                />

                <div className="grid grid-cols-1 gap-5 lg:grid-cols-[240px_1fr_320px]">
                    {/* ============ LEFT: photo grid ============ */}
                    <section className="td-fade-up td-delay-1 rounded-xl border border-zinc-800 bg-zinc-900/60 p-3 shadow-sm">
                        <div className="mb-2 flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-zinc-100">Photos</h3>
                            {permissions.can_upload && (
                                <>
                                    <button
                                        onClick={() => uploadRef.current?.click()}
                                        disabled={busy !== null}
                                        className="td-press inline-flex items-center gap-1.5 rounded-md bg-zinc-800 px-2.5 py-1.5 text-xs font-medium text-zinc-100 transition hover:bg-zinc-700 disabled:cursor-not-allowed disabled:opacity-40"
                                    >
                                        {busy === 'upload' ? (<><span className="td-spinner" aria-hidden="true" /> Uploading…</>) : (<><span aria-hidden="true">+</span> Upload</>)}
                                    </button>
                                    <input
                                        ref={uploadRef}
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        multiple
                                        className="hidden"
                                        onChange={(e) => doUpload(e.target.files)}
                                    />
                                </>
                            )}
                        </div>
                        <div className="grid grid-cols-3 gap-2">
                            {photos.map((p) => {
                                const rec = recFor.get(p.id);
                                const recMeta = rec ? RECOMMENDATION_META[rec.recommendation] : null;
                                return (
                                <div key={p.id} className="group relative">
                                    <button
                                        onClick={() => setSelectedId(p.id)}
                                        className={`td-press w-full overflow-hidden rounded-md border-2 transition-all duration-200 hover:border-zinc-500 ${selectedId === p.id ? 'border-amber-500' : 'border-transparent'} ${p.selection_state === 'culled' ? 'opacity-60 grayscale' : ''}`}
                                    >
                                        {p.url ? (
                                            <img src={p.url} alt={p.filename} className="aspect-square w-full object-cover" loading="lazy" />
                                        ) : (
                                            <div className="flex aspect-square w-full items-center justify-center bg-zinc-800 text-xs text-zinc-500">no img</div>
                                        )}
                                    </button>
                                    {recMeta && (
                                        <span
                                            data-testid={`rec-badge-${p.id}`}
                                            onClick={() => setSelectedId(p.id)}
                                            role="button"
                                            tabIndex={0}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter' || e.key === ' ') setSelectedId(p.id);
                                            }}
                                            className={`absolute left-1 bottom-1 cursor-pointer rounded px-1 text-xs font-bold tracking-wide ${recMeta.badge}`}
                                            title={`Agent recommendation: ${recMeta.label} (${confidencePct(rec?.confidence ?? 0)})`}
                                        >
                                            {recMeta.label}
                                        </span>
                                    )}
                                    {p.selection_state === 'culled' && (
                                        <span className="absolute left-1 top-1 rounded bg-rose-600 px-1 text-xs font-bold text-zinc-100">CULL</span>
                                    )}
                                    <label className="absolute right-1 top-1 cursor-pointer rounded bg-black/50 p-0.5 text-zinc-100">
                                        <input
                                            type="checkbox"
                                            checked={cullIds.includes(p.id)}
                                            onChange={() => toggleCull(p.id)}
                                            className="h-3 w-3"
                                        />
                                    </label>
                                </div>
                                );
                            })}
                        </div>
                    </section>

                    {/* ============ CENTER: selected photo / overview ============ */}
                    <section className="td-fade-up td-delay-2 rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 shadow-sm">
                        {selected ? (
                            <>
                                <div className="mb-3 flex items-start justify-between gap-3">
                                    <h3 className="min-w-0 text-sm font-semibold text-zinc-100">
                                        {selected.filename}
                                        <span className="ms-2 text-xs font-normal text-zinc-500">
                                            {selected.width && selected.height ? `${selected.width}×${selected.height}` : ''}
                                        </span>
                                    </h3>
                                    <div className="flex shrink-0 items-center gap-2 text-xs">
                                        <div className="flex gap-2">
                                            <span className={`rounded-full px-2 py-0.5 font-medium ${selected.selection_state === 'selected' ? 'bg-emerald-500/15 text-emerald-300' : selected.selection_state === 'culled' ? 'bg-rose-500/15 text-rose-300' : 'bg-zinc-900 text-zinc-300'}`}>
                                                {selected.selection_state}
                                            </span>
                                            <span className="rounded-full bg-indigo-500/15 px-2 py-0.5 font-medium text-indigo-400">{selected.retouch_state}</span>
                                        </div>
                                        {permissions.can_upload && (
                                            <button
                                                type="button"
                                                aria-label={`Delete ${selected.filename}`}
                                                data-testid="workspace-delete-photo"
                                                onClick={() => setDeletePhotoId(selected.id)}
                                                disabled={busy !== null || deletingPhoto}
                                                className="td-press rounded-md border border-rose-500/50 bg-rose-500/10 px-2.5 py-1.5 text-xs font-semibold text-rose-300 transition hover:bg-rose-500/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-400 disabled:cursor-not-allowed disabled:opacity-40"
                                            >
                                                Delete
                                            </button>
                                        )}
                                    </div>
                                </div>
                                {selected.url ? (
                                    <img src={selected.url} alt={selected.filename} className="w-full rounded-lg border border-zinc-800" />
                                ) : (
                                    <div className="flex h-64 items-center justify-center rounded-lg bg-zinc-900 text-sm text-zinc-500">No preview</div>
                                )}
                                <PhotoExifGrid photo={selected} inspected={eager.get(selected.id)} />

                                {analysisError && (
                                    <div
                                        role="alert"
                                        data-testid="analysis-error"
                                        className="mt-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-300"
                                    >
                                        <p className="font-medium">Photo analysis failed: {analysisError}</p>
                                        <button
                                            type="button"
                                            onClick={() => setAnalysisRefresh((version) => version + 1)}
                                            disabled={busy !== null}
                                            className="mt-1 rounded border border-rose-500/40 px-2 py-0.5 text-xs font-semibold text-rose-400 hover:bg-rose-500/15 disabled:opacity-40"
                                        >
                                            Retry analysis
                                        </button>
                                    </div>
                                )}

                                {/* ============ Sprint 3 — context-aware culling card ============ */}
                                {selectedRec && (
                                    <div
                                        data-testid="culling-card"
                                        className="mt-4 rounded-xl border border-zinc-800 bg-zinc-950/60 p-4"
                                    >
                                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                            <h4 className="text-sm font-semibold text-zinc-100">Context-Aware Culling</h4>
                                            <div className="flex items-center gap-2">
                                                <span
                                                    data-testid="recommendation-badge"
                                                    className={`rounded-full px-2.5 py-1 text-xs font-bold tracking-wide ${RECOMMENDATION_META[selectedRec.recommendation]?.badge ?? 'bg-zinc-900 text-zinc-300'}`}
                                                >
                                                    {RECOMMENDATION_META[selectedRec.recommendation]?.label ?? selectedRec.recommendation}
                                                </span>
                                                <span
                                                    data-testid="recommendation-confidence"
                                                    className="text-xs font-medium text-zinc-500"
                                                    title="Recommendation confidence — never certainty"
                                                >
                                                    {confidencePct(selectedRec.confidence)} confidence
                                                </span>
                                            </div>
                                        </div>

                                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                            {/* TECHNICAL QUALITY */}
                                            <div data-testid="technical-section">
                                                <div className="mb-1.5 flex items-baseline justify-between">
                                                    <h5 className="text-xs font-bold uppercase tracking-wide text-zinc-500">Technical quality</h5>
                                                    {analysis?.observation && (
                                                        <span
                                                            data-testid="technical-provenance"
                                                            className="text-xs text-zinc-400"
                                                            title={`Provenance: ${analysis.observation.technical_provenance}`}
                                                        >
                                                            {provenanceLabel(analysis.observation.technical_provenance)}
                                                        </span>
                                                    )}
                                                </div>
                                                <dl className="space-y-1 text-xs text-zinc-300">
                                                    <div className="flex justify-between gap-2"><dt>Sharpness</dt><dd className="font-medium text-zinc-100">{assessmentText(analysis?.observation?.technical?.sharpness)}</dd></div>
                                                    <div className="flex justify-between gap-2"><dt>Exposure</dt><dd className="font-medium text-zinc-100">{assessmentText(analysis?.observation?.technical?.exposure)}</dd></div>
                                                    <div className="flex justify-between gap-2"><dt>Motion blur</dt><dd className="font-medium text-zinc-100">{assessmentText(analysis?.observation?.technical?.motion_blur)}</dd></div>
                                                    <div className="flex justify-between gap-2"><dt>Highlight clipping</dt><dd className="font-medium text-zinc-100">{assessmentText(analysis?.observation?.technical?.highlight_clipping)}</dd></div>
                                                    <div className="flex justify-between gap-2" data-testid="similarity-group">
                                                        <dt>Similarity group</dt>
                                                        <dd className="font-medium text-zinc-100">
                                                            {selectedRec.similarity_group && selectedRec.similarity_group_size > 1
                                                                ? `burst group · ${selectedRec.similarity_group_size} similar frame(s)`
                                                                : 'unique frame'}
                                                        </dd>
                                                    </div>
                                                </dl>
                                                <p className="mt-2 text-xs leading-relaxed text-zinc-300" data-testid="technical-rationale">{selectedRec.technical_rationale}</p>
                                            </div>

                                            {/* CREATIVE FIT */}
                                            <div data-testid="creative-section">
                                                <div className="mb-1.5 flex items-baseline justify-between">
                                                    <h5 className="text-xs font-bold uppercase tracking-wide text-zinc-500">Creative fit</h5>
                                                    {analysis?.observation && (
                                                        <span
                                                            data-testid="creative-provenance"
                                                            className="text-xs text-zinc-400"
                                                            title={`Provenance: ${analysis.observation.creative_provenance} — creative labels come from the documented demo annotation, not from pixel inference`}
                                                        >
                                                            {provenanceLabel(analysis.observation.creative_provenance)}
                                                        </span>
                                                    )}
                                                </div>
                                                {analysis?.observation && analysis.observation.creative_provenance === 'demo_sidecar_annotation' ? (
                                                    <dl className="space-y-1 text-xs text-zinc-300">
                                                        <div className="flex justify-between gap-2"><dt>Emotional strength</dt><dd className="font-medium text-zinc-100">{analysis.observation.creative.emotion_strength.replace(/_/g, ' ')}</dd></div>
                                                        <div className="flex justify-between gap-2"><dt>Candidness</dt><dd className="font-medium text-zinc-100">{analysis.observation.creative.candidness.replace(/_/g, ' ')}</dd></div>
                                                        <div className="flex justify-between gap-2"><dt>Mood</dt><dd className="font-medium text-zinc-100">{analysis.observation.creative.mood.length > 0 ? analysis.observation.creative.mood.join(', ') : '—'}</dd></div>
                                                        <div className="flex justify-between gap-2"><dt>Storytelling</dt><dd className="font-medium text-zinc-100">{analysis.observation.creative.environmental_storytelling.replace(/_/g, ' ')}</dd></div>
                                                    </dl>
                                                ) : (
                                                    <p className="text-xs italic text-zinc-400">
                                                        Creative context not available for this frame — creative fit cannot be evaluated.
                                                    </p>
                                                )}
                                                <p className="mt-2 text-xs leading-relaxed text-zinc-300" data-testid="creative-rationale">{selectedRec.creative_rationale}</p>
                                            </div>
                                        </div>

                                        {/* WHY */}
                                        <div className="mt-3 rounded-lg border border-indigo-500/20 bg-indigo-500/15 p-3" data-testid="why-section">
                                            <h5 className="text-xs font-bold uppercase tracking-wide text-indigo-400">Why</h5>
                                            <p className="mt-1 text-xs leading-relaxed text-zinc-200" data-testid="tradeoff-explanation">
                                                {tradeoffParts(selectedRec.tradeoff).before}{' '}
                                                {tradeoffParts(selectedRec.tradeoff).brief && (
                                                    <b data-testid="brief-linkage">{tradeoffParts(selectedRec.tradeoff).brief}</b>
                                                )}
                                            </p>
                                        </div>

                                        {/* INFLUENCED BY */}
                                        <div className="mt-2.5 flex flex-wrap items-center gap-1.5" data-testid="influenced-by">
                                            <span className="text-xs font-semibold uppercase tracking-wide text-zinc-400">Influenced by</span>
                                            {selectedRec.influenced_by.length === 0 ? (
                                                <span className="text-xs italic text-zinc-400">no brief dimension moved this call</span>
                                            ) : (
                                                selectedRec.influenced_by.map((dim) => (
                                                    <code key={dim} className="rounded bg-zinc-800/80 px-1.5 py-0.5 text-xs font-semibold text-zinc-200">{dim}</code>
                                                ))
                                            )}
                                        </div>

                                        {/* PHOTOGRAPHER ACTIONS — HUMAN authority, never a WebMCP tool */}
                                        {canPhotographerAct && (
                                            <div className="mt-3 border-t border-zinc-800 pt-3" data-testid="photographer-actions">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="text-xs font-semibold uppercase tracking-wide text-zinc-400">Your decision</span>
                                                    {(['keep', 'review', 'reject'] as CullingChoice[]).map((choice) => {
                                                        const active = myDecisions[selected.id]?.decision === choice
                                                            || (selected.selection_state === 'selected' && choice === 'keep' && !myDecisions[selected.id])
                                                            || (selected.selection_state === 'culled' && choice === 'reject' && !myDecisions[selected.id]);
                                                        return (
                                                            <button
                                                                key={choice}
                                                                onClick={() => void recordCullingDecision(selected.id, choice)}
                                                                disabled={busy !== null}
                                                                className={`rounded-md px-3 py-1.5 text-xs font-semibold transition disabled:opacity-40 ${
                                                                    active
                                                                        ? 'bg-zinc-800 text-zinc-100'
                                                                        : choice === 'keep'
                                                                            ? 'border border-emerald-500/40 bg-emerald-500/10 text-emerald-300 hover:bg-emerald-500/15'
                                                                            : choice === 'reject'
                                                                                ? 'border border-rose-500/40 bg-rose-500/10 text-rose-300 hover:bg-rose-500/15'
                                                                                : 'border border-amber-400/40 bg-amber-400/10 text-amber-300 hover:bg-amber-400/10'
                                                                }`}
                                                            >
                                                                {choice.charAt(0).toUpperCase() + choice.slice(1)}
                                                            </button>
                                                        );
                                                    })}
                                                    <button
                                                        onClick={() => setOverrideOpen((o) => !o)}
                                                        disabled={busy !== null}
                                                        className="rounded-md border border-indigo-500/40 bg-zinc-900/60 px-3 py-1.5 text-xs font-semibold text-indigo-400 hover:bg-indigo-500/10 disabled:opacity-40"
                                                        title="Override the agent's recommendation with your reasoning"
                                                    >
                                                        Override
                                                    </button>
                                                </div>
                                                {overrideOpen && (
                                                    <div className="mt-2 rounded-lg border border-indigo-500/30 bg-zinc-900/60 p-2.5">
                                                        <label htmlFor="override-note" className="text-xs font-medium text-zinc-300">
                                                            Why does the agent's call miss? (optional, saved with your decision)
                                                        </label>
                                                        <input
                                                            id="override-note"
                                                            type="text"
                                                            value={overrideNote}
                                                            onChange={(e) => setOverrideNote(e.target.value)}
                                                            placeholder='e.g. "The expression matters more than the softness."'
                                                            className="mt-1 w-full rounded-md border border-zinc-700 px-2 py-1.5 text-xs focus:border-amber-400/60 focus:outline-none"
                                                        />
                                                        <div className="mt-2 flex flex-wrap gap-2">
                                                            {(['keep', 'review', 'reject'] as CullingChoice[]).map((choice) => (
                                                                <button
                                                                    key={choice}
                                                                    onClick={() => {
                                                                        void recordCullingDecision(selected.id, choice, overrideNote.trim() || undefined, true);
                                                                        setOverrideNote('');
                                                                        setOverrideOpen(false);
                                                                    }}
                                                                    disabled={busy !== null}
                                                                    className="rounded-md bg-amber-400 px-3 py-1.5 text-xs font-semibold text-zinc-100 hover:bg-amber-400 disabled:opacity-40"
                                                                >
                                                                    Override → {choice.charAt(0).toUpperCase() + choice.slice(1)}
                                                                </button>
                                                            ))}
                                                        </div>
                                                    </div>
                                                )}
                                                {myDecisions[selected.id] && (
                                                    <p className="mt-2 text-xs text-zinc-500" data-testid="decision-persisted">
                                                        Recorded <b>{myDecisions[selected.id].decision.toUpperCase()}</b>
                                                        {myDecisions[selected.id].override ? ' (override)' : ''}
                                                        {myDecisions[selected.id].note ? ` — "${myDecisions[selected.id].note}"` : ''}
                                                        {' '}· persisted to photographer_decisions.
                                                    </p>
                                                )}
                                            </div>
                                        )}
                                        {isAgent && (
                                            <p className="mt-3 border-t border-zinc-800 pt-2 text-xs italic text-zinc-400" data-testid="agent-no-final-authority">
                                                Agent view — recommendations only. Culling is finalized exclusively by the photographer.
                                            </p>
                                        )}
                                    </div>
                                )}
                                {cullingError && (
                                    <div
                                        role="alert"
                                        data-testid="culling-error"
                                        className="mt-3 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-300"
                                    >
                                        <p className="font-medium">Culling context failed: {cullingError}</p>
                                        <button
                                            type="button"
                                            onClick={() => void loadCulling()}
                                            disabled={busy !== null || cullingLoading}
                                            className="mt-1 rounded border border-rose-500/40 px-2 py-0.5 text-xs font-semibold text-rose-400 hover:bg-rose-500/15 disabled:opacity-40"
                                        >
                                            Retry culling context
                                        </button>
                                    </div>
                                )}
                                {!selectedRec && !cullingLoading && !cullingError && (
                                    <div className="mt-3 rounded-md border border-amber-400/30 bg-amber-400/10 px-3 py-2 text-xs text-amber-200">
                                        <p className="font-medium">Analysis has not run for this frame.</p>
                                        <p className="mt-0.5 text-amber-300">
                                            {isAgent
                                                ? 'Run Analyze Project Photos to create non-final observations before requesting a recommendation.'
                                                : 'An agent must explicitly analyze project photos before this frame can receive a recommendation.'}
                                        </p>
                                        {isAgent && (
                                            <button
                                                type="button"
                                                onClick={runAnalyze}
                                                disabled={busy !== null}
                                                data-testid="analysis-required-action"
                                                className="mt-2 rounded border border-amber-400/40 bg-zinc-900/60 px-2 py-1 text-xs font-semibold text-amber-200 hover:bg-amber-400/10 disabled:opacity-40"
                                            >
                                                {busy === 'analyze' ? 'Analyzing…' : 'Analyze Project Photos'}
                                            </button>
                                        )}
                                    </div>
                                )}
                            </>
                        ) : (
                            <div className="flex h-64 items-center justify-center rounded-lg bg-zinc-950/40 text-sm text-zinc-500">
                                No photo selected.
                            </div>
                        )}

                        {/* Project overview */}
                        <div className="mt-5 border-t border-zinc-800/70 pt-4">
                            <h4 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">Project overview</h4>
                            <p className="mt-1 text-sm text-zinc-300">{project.description ?? 'No description.'}</p>
                            <dl className="mt-3 grid grid-cols-2 gap-2 text-xs">
                                <div className="rounded-lg bg-zinc-950/40 p-2"><dt className="text-zinc-400">Owner</dt><dd className="font-medium text-zinc-100">{project.owner ?? '—'}</dd></div>
                                <div className="rounded-lg bg-zinc-950/40 p-2"><dt className="text-zinc-400">Status</dt><dd className="font-medium text-zinc-100">{project.status}</dd></div>
                                <div className="rounded-lg bg-zinc-950/40 p-2"><dt className="text-zinc-400">Photos</dt><dd className="font-medium text-zinc-100">{photos.length}</dd></div>
                                <div className="rounded-lg bg-zinc-950/40 p-2"><dt className="text-zinc-400">Eligible for execute</dt><dd className="font-medium text-zinc-100">{eligibleForExecution ? 'Yes' : 'No'}</dd></div>
                                {culling && (
                                    <div className="rounded-lg bg-zinc-950/40 p-2" data-testid="culling-context-summary">
                                        <dt className="text-zinc-400">Context-aware culling</dt>
                                        <dd className="font-medium text-zinc-100">
                                            {culling.context.photos_observed}/{photos.length} observed
                                            {culling.has_direction ? ' · brief applied' : ' · no adopted brief'}
                                            {culling.context.duplicate_groups.length > 0 ? ` · ${culling.context.duplicate_groups.length} similarity group(s)` : ''}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        </div>
                    </section>

                    {/* ============ RIGHT: Creative Direction + Retouch + QA + Agent Proposal + Memory ============ */}
                    <section className="td-fade-up td-delay-3 space-y-5">
                        {/* Creative Direction */}
                        <div className="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 shadow-sm">
                            <h3 className="mb-2 text-sm font-semibold text-zinc-100">Creative Direction</h3>
                            {brief ? (
                                <div className="space-y-2 text-xs text-zinc-300">
                                    <p><b>Client:</b> {brief.client ?? '—'}</p>
                                    <p><b>Location:</b> {brief.location ?? '—'} · <b>Shoot:</b> {brief.shoot_date ?? '—'}</p>
                                    <p className="text-zinc-200">{brief.creative_direction ?? '—'}</p>
                                    <p className="text-zinc-500 italic">{brief.tonality_notes ?? ''}</p>
                                    <p className="text-zinc-200"><b>Deliverables:</b> {brief.deliverables ?? '—'}</p>
                                </div>
                            ) : (
                                <p className="text-xs text-zinc-500">No brief yet.</p>
                            )}
                        </div>

                        <DecisionLedger decisions={decisions} />

                        {/* ============ Sprint 4 — RETOUCH PANEL: ORIGINAL vs APPROVED ============ */}
                        <div className="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 shadow-sm" data-testid="retouch-panel">
                            <div className="mb-2 flex items-center justify-between">
                                <h3 className="text-sm font-semibold text-zinc-100">Retouch</h3>
                                {retouchCard && (
                                    <span className={`rounded-full px-2 py-0.5 text-xs font-bold ${
                                        retouchCard.status === 'executed'
                                            ? 'bg-zinc-800 text-zinc-100'
                                            : retouchCard.status === 'approved'
                                                ? 'bg-emerald-500/15 text-emerald-400'
                                                : 'bg-amber-400/10 text-amber-400'
                                    }`} data-testid="retouch-status">
                                        {retouchCard.status === 'executed' ? 'APPROVED BY PHOTOGRAPHER' : STATE_LABEL[retouchCard.status] ?? retouchCard.status}
                                    </span>
                                )}
                            </div>

                            {retouchCard ? (
                                <div>
                                    {/* BEFORE / AFTER — real image sources only */}
                                    <div className="grid grid-cols-2 gap-2" data-testid="before-after">
                                        <figure>
                                            <p className="mb-1 text-xs font-bold uppercase tracking-wide text-zinc-400">ORIGINAL</p>
                                            {retouchCard.original.url ? (
                                                <img src={retouchCard.original.url} alt="original" data-testid="original-image" className="w-full rounded-lg border border-zinc-800" />
                                            ) : (
                                                <div className="flex h-28 items-center justify-center rounded-lg bg-zinc-900 text-xs text-zinc-400">no original</div>
                                            )}
                                        </figure>
                                        <figure>
                                            <p className="mb-1 text-xs font-bold uppercase tracking-wide text-zinc-400">APPROVED / PREVIEW</p>
                                            {retouchCard.derivative?.url ? (
                                                <img src={retouchCard.derivative.url} alt="approved derivative" data-testid="derivative-image" className="w-full rounded-lg border-2 border-emerald-500/40" />
                                            ) : retouchRenderFailed ? (
                                                <div
                                                    className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-4 text-xs text-rose-300"
                                                    data-testid="retouch-render-error"
                                                    role="alert"
                                                >
                                                    Retouch render failed — no approved derivative was stored.
                                                </div>
                                            ) : (
                                                <div className="flex h-28 items-center justify-center rounded-lg border border-dashed border-zinc-700 text-xs text-zinc-400" data-testid="derivative-placeholder">
                                                    {retouchCard.status === 'approved' ? 'Not rendered yet — execute the approved plan' : 'Not executed yet'}
                                                </div>
                                            )}
                                        </figure>
                                    </div>

                                    {/* Creative Brief influence */}
                                    {retouchCard.agent_original.influenced_by.length > 0 && (
                                        <div className="mt-2 flex flex-wrap items-center gap-1.5" data-testid="retouch-influenced-by">
                                            <span className="text-xs font-semibold uppercase tracking-wide text-zinc-400">Brief influence</span>
                                            {retouchCard.agent_original.influenced_by.map((dim) => (
                                                <code key={dim} className="rounded bg-zinc-800/80 px-1.5 py-0.5 text-xs font-semibold text-zinc-200">{dim}</code>
                                            ))}
                                        </div>
                                    )}

                                    {/* THREE-LAYER VALUE HISTORY — the trust core */}
                                    <dl className="mt-3 space-y-1 text-xs" data-testid="retouch-layers">
                                        <div className="flex items-baseline justify-between gap-2" data-testid="layer-agent-original">
                                            <dt className="text-zinc-500">AI PROPOSAL</dt>
                                            <dd className="font-semibold text-zinc-100">{fmtAdjustments(retouchCard.agent_original.params)}</dd>
                                        </div>
                                        {retouchCard.photographer_modification?.adjustments && (
                                            <div className="flex items-baseline justify-between gap-2" data-testid="layer-photographer-modified">
                                                <dt className="text-zinc-500">PHOTOGRAPHER MODIFIED{retouchCard.photographer_modification.note ? ' — ' + retouchCard.photographer_modification.note : ''}</dt>
                                                <dd className="font-semibold text-indigo-400">{fmtAdjustments(retouchCard.photographer_modification.adjustments)}</dd>
                                            </div>
                                        )}
                                        {retouchCard.executed?.params && (
                                            <div className="flex items-baseline justify-between gap-2 border-t border-zinc-800/70 pt-1" data-testid="layer-executed">
                                                <dt className="font-semibold text-zinc-100">FINAL APPROVED VALUES</dt>
                                                <dd className="font-bold text-emerald-400">{fmtAdjustments(retouchCard.executed.params)}</dd>
                                            </div>
                                        )}
                                    </dl>

                                    {/* Human-authority status */}
                                    <p className="mt-2 rounded-md bg-emerald-500/10 px-2 py-1.5 text-xs font-semibold text-emerald-300" data-testid="human-authority-status">
                                        {retouchCard.executed
                                            ? 'APPROVED BY PHOTOGRAPHER — only the human-approved values were executed.'
                                            : retouchCard.status === 'approved'
                                                ? 'APPROVED — awaiting execution via apply_approved_plan.'
                                                : retouchCard.status === 'modified'
                                                    ? 'MODIFIED BY PHOTOGRAPHER — superseding proposal pending review.'
                                                    : 'PENDING PHOTOGRAPHER REVIEW — nothing executes without photographer approval.'}
                                    </p>

                                    {/* Real before/after evidence: checksums make it verifiable */}
                                    <div className="mt-2 space-y-0.5 text-xs text-zinc-400" data-testid="retouch-evidence">
                                        <p>original sha256: <code data-testid="original-sha">{retouchCard.original.sha256 ? `${retouchCard.original.sha256.slice(0, 16)}…` : '—'}</code></p>
                                        {retouchCard.derivative && (
                                            <>
                                                <p>derivative sha256: <code data-testid="derivative-sha">{retouchCard.derivative.sha256?.slice(0, 16)}…</code> ({retouchCard.derivative.storage_path})</p>
                                                <p data-testid="checksum-divergence" className={retouchCard.derivative.sha256 && retouchCard.original.sha256 && retouchCard.derivative.sha256 !== retouchCard.original.sha256 ? 'text-emerald-400' : 'text-rose-400'}>
                                                    {retouchCard.derivative.sha256 !== retouchCard.original.sha256
                                                        ? '✓ derivative differs from original — original unchanged (byte-for-byte verified at execute time)'
                                                        : '✗ derivative checksum equals original — inspect!'}
                                                </p>
                                            </>
                                        )}
                                    </div>
                                </div>
                            ) : (
                                <p className="text-xs text-zinc-400">No retouch proposal yet — run the agent's Propose Retouch Plan.</p>
                            )}
                        </div>

                        {/* ============ Sprint 4 — CONSISTENCY QA PANEL ============ */}
                        <div className="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 shadow-sm" data-testid="qa-panel">
                            <div className="mb-2 flex items-center justify-between">
                                <h3 className="text-sm font-semibold text-zinc-100">Consistency QA</h3>
                                <span className="text-xs text-zinc-400" data-testid="qa-summary">
                                    {qaFindings.length === 0
                                        ? 'no findings yet'
                                        : `${qaFindings.filter((f) => f.status === 'open').length} open · ${qaFindings.filter((f) => f.status !== 'open').length} resolved`}
                                </span>
                            </div>
                            {qaFindings.length === 0 ? (
                                <p className="text-xs text-zinc-400">Run the agent's consistency review to populate findings.</p>
                            ) : (
                                <ul className="space-y-2">
                                    {qaFindings.map((f) => (
                                        <li key={f.id} className="rounded-lg border border-zinc-800 p-2" data-testid={`qa-finding-${f.id}`}>
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="text-xs font-bold text-zinc-100" data-testid={`qa-category-${f.id}`}>{f.category.replace(/_/g, ' ')}</span>
                                                <span className={`rounded-full px-1.5 py-0.5 text-xs font-bold ${
                                                    f.severity === 'info' ? 'bg-sky-500/15 text-sky-400'
                                                        : f.severity === 'low' ? 'bg-amber-400/10 text-amber-400'
                                                            : f.severity === 'medium' || f.severity === 'warning' ? 'bg-amber-400/10 text-amber-300'
                                                                : 'bg-rose-500/15 text-rose-400'
                                                }`} data-testid={`qa-severity-${f.id}`}>{f.severity.toUpperCase()}</span>
                                            </div>
                                            <p className="mt-1 text-xs text-zinc-300">{f.message}</p>
                                            {f.details?.explanation && (
                                                <p className="mt-1 text-xs leading-relaxed text-zinc-500" data-testid={`qa-why-${f.id}`}>
                                                    <b>WHY:</b> {f.details.explanation}
                                                </p>
                                            )}
                                            {f.details?.expected && (
                                                <p className="mt-0.5 text-xs text-zinc-500"><b>Expected:</b> {f.details.expected}</p>
                                            )}
                                            {f.details?.influenced_by && f.details.influenced_by.length > 0 && (
                                                <div className="mt-1 flex flex-wrap items-center gap-1" data-testid={`qa-influenced-${f.id}`}>
                                                    <span className="text-xs font-semibold uppercase tracking-wide text-zinc-400">influenced_by</span>
                                                    {f.details.influenced_by?.map((ref) => (
                                                        <code key={ref} className="rounded bg-zinc-800/80 px-1.5 py-0.5 text-xs font-semibold text-zinc-200">{ref}</code>
                                                    ))}
                                                </div>
                                            )}
                                            <div className="mt-1.5 flex items-center gap-2">
                                                <span className={`rounded-full px-2 py-0.5 text-xs font-bold ${f.status === 'open' ? 'bg-amber-400/10 text-amber-400' : f.status === 'acknowledged' ? 'bg-sky-500/15 text-sky-400' : 'bg-zinc-800 text-zinc-300'}`} data-testid={`qa-status-${f.id}`}>
                                                    {f.status}
                                                </span>
                                                {canPhotographerAct && f.status === 'open' && (
                                                    <div className="flex gap-1.5">
                                                        <button
                                                            onClick={() => void respondQaFinding(f, 'acknowledge')}
                                                            disabled={busy !== null}
                                                            className="rounded border border-sky-500/40 px-2 py-0.5 text-xs font-semibold text-sky-400 hover:bg-sky-500/10 disabled:opacity-40"
                                                        >
                                                            Acknowledge
                                                        </button>
                                                        <button
                                                            onClick={() => setDismissQaTargetId(f.id)}
                                                            disabled={busy !== null}
                                                            className="rounded border border-zinc-600 px-2 py-0.5 text-xs font-semibold text-zinc-300 hover:bg-zinc-800/60 disabled:opacity-40"
                                                        >
                                                            Dismiss
                                                        </button>
                                                    </div>
                                                )}
                                                {isAgent && <span className="text-xs italic text-zinc-400">agent view — QA actions are photographer authority</span>}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>

                        {/* ============ Sprint 4 — CREATIVE MEMORY ============ */}
                        <div className="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 shadow-sm" data-testid="creative-memory-panel">
                            <h3 className="mb-1 text-sm font-semibold text-zinc-100">Creative Memory</h3>
                            <p className="mb-2 text-xs leading-relaxed text-zinc-400">
                                Photographer decision history — explicit lessons you record. Future proposals read them as
                                deterministic context; this is not machine-learned personalization.
                            </p>
                            {canPhotographerAct && (
                                <div className="mb-2 flex gap-1.5">
                                    <input
                                        type="text"
                                        value={memoryDraft}
                                        onChange={(e) => setMemoryDraft(e.target.value)}
                                        placeholder='e.g. "Less warm." "Preserve grain."'
                                        className="flex-1 rounded border border-zinc-700 px-2 py-1 text-xs"
                                        aria-label="New creative memory lesson"
                                    />
                                    <button
                                        onClick={() => void storeMemory(memoryDraft)}
                                        disabled={busy !== null}
                                        className="td-press inline-flex items-center gap-1.5 rounded-md bg-zinc-800 px-2.5 py-1 text-xs font-semibold text-zinc-100 transition hover:bg-zinc-700 disabled:cursor-not-allowed disabled:opacity-40"
                                    >
                                        {busy === 'memory' ? (<><span className="td-spinner" aria-hidden="true" /> Saving…</>) : 'Save'}
                                    </button>
                                </div>
                            )}
                            <ul className="space-y-1.5" data-testid="memory-list">
                                {memories.length === 0 ? (
                                    <li className="text-xs text-zinc-400">No lessons yet.</li>
                                ) : (
                                    memories.map((m) => (
                                        <li key={m.id} className="rounded-md border border-zinc-800/70 bg-zinc-950/70 p-2 text-xs" data-testid={`memory-${m.id}`}>
                                            <p className="text-zinc-200">“{m.lesson}”</p>
                                            <p className="mt-0.5 text-xs text-zinc-400">
                                                {entityName(m.photographer) || 'photographer'} · {m.kind} · {fmtTime(m.created_at)}
                                            </p>
                                        </li>
                                    ))
                                )}
                            </ul>
                        </div>
                        {/* Agent proposal controls */}
                        <div className="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 shadow-sm">
                            <h3 className="mb-2 text-sm font-semibold text-zinc-100">Agent Proposal</h3>
                            <div className="space-y-2">
                                <button
                                    onClick={runProposeCull}
                                    disabled={busy !== null || !isAgent}
                                    className="td-press flex w-full items-center justify-center gap-2 rounded-md bg-amber-600 px-3 py-2 text-xs font-semibold text-zinc-100 transition hover:bg-amber-300 disabled:cursor-not-allowed disabled:opacity-40"
                                    title={!isAgent ? 'Log in as the agent to propose.' : 'Create a cull proposal (does not change selections)'}
                                >
                                    {busy === 'cull' ? (<><span className="td-spinner" aria-hidden="true" /> Proposing…</>) : `Propose Cull (${cullIds.length} selected)`}
                                </button>
                                <button
                                    onClick={runRetouchPlan}
                                    disabled={busy !== null || !isAgent}
                                    className="td-press flex w-full items-center justify-center gap-2 rounded-md bg-amber-400 px-3 py-2 text-xs font-semibold text-zinc-100 transition hover:bg-amber-300 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    {busy === 'retouch' ? (<><span className="td-spinner" aria-hidden="true" /> Proposing…</>) : 'Propose Retouch Plan'}
                                </button>
                                {isAgent && (
                                    <button
                                        onClick={runAnalyze}
                                        disabled={busy !== null}
                                        className="td-press flex w-full items-center justify-center gap-2 rounded-md bg-violet-500 px-3 py-2 text-xs font-semibold text-zinc-100 transition hover:bg-violet-400 disabled:cursor-not-allowed disabled:opacity-40"
                                        title="Persist non-final photo observations before reading recommendations"
                                    >
                                        {busy === 'analyze' ? (<><span className="td-spinner" aria-hidden="true" /> Analyzing…</>) : 'Analyze Project Photos'}
                                    </button>
                                )}
                                <button
                                    onClick={runReview}
                                    disabled={busy !== null || !isAgent}
                                    className="td-press flex w-full items-center justify-center gap-2 rounded-md bg-zinc-700 px-3 py-2 text-xs font-semibold text-zinc-100 transition hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    {busy === 'review' ? (<><span className="td-spinner" aria-hidden="true" /> Reviewing…</>) : 'Run Consistency Review'}
                                </button>
                                {!isAgent && (
                                    <p className="text-xs text-zinc-400">
                                        {canPhotographerAct ? 'You are signed in as photographer.' : 'Viewer access is read-only.'}
                                    </p>
                                )}
                            </div>

                            {/* Pending proposals — review by photographer */}
                            <div className="mt-3 border-t border-zinc-800/70 pt-3">
                                <h4 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">Proposals</h4>
                                {localProposals.length === 0 ? (
                                    <p className="mt-2 text-xs text-zinc-400">No proposals yet.</p>
                                ) : (
                                    <ul className="mt-2 space-y-2">
                                        {localProposals.map((p) => (
                                            <li key={p.id} className="rounded-lg border border-zinc-800 p-2">
                                                <div className="flex items-center justify-between">
                                                    <span className="text-xs font-semibold text-zinc-100">
                                                        #{p.id} {TYPE_LABEL[p.type] ?? p.type}
                                                    </span>
                                                    <span className={`rounded-full px-2 py-0.5 text-xs font-bold ${p.status === 'approved' ? 'bg-emerald-500/15 text-emerald-400' : p.status === 'executed' ? 'bg-zinc-800 text-zinc-100' : p.status === 'rejected' ? 'bg-rose-500/15 text-rose-400' : 'bg-amber-400/10 text-amber-400'}`}>
                                                        {STATE_LABEL[p.status] ?? p.status}
                                                    </span>
                                                </div>
                                                {p.summary && <p className="mt-1 text-xs text-zinc-500">{p.summary}</p>}
                                                <div className="mt-1 text-xs text-zinc-400">
                                                    {p.items.length === 0 && p.status === 'draft'
                                                        ? '0 item(s) — awaiting agent generation'
                                                        : `${p.items.length} item(s)`}
                                                    {' · '}
                                                    {p.created_by ?? 'agent'} · {fmtTime(p.created_at)}
                                                </div>

                                                {/* Retouch proposal value layers — makes human authority obvious */}
                                                {(p.type === 'retouch' || p.type === 'batch_retouch') && p.items[0]?.params && (
                                                    <div className="mt-1.5 space-y-0.5 text-xs" data-testid={`proposal-${p.id}-values`}>
                                                        <p className="text-zinc-300">
                                                            AI proposal: <b data-testid="ai-proposed-values">{fmtAdjustments(agentValuesFor(p))}</b>
                                                        </p>
                                                        {(p.status === 'approved' || p.status === 'executed') && (
                                                            <p className="text-zinc-300">
                                                                Photographer modified: <b data-testid="photographer-modified-values">{fmtAdjustments(photographerValuesFor(p) ?? agentValuesFor(p))}</b>
                                                            </p>
                                                        )}
                                                        {p.status === 'executed' && (
                                                            <p className="text-zinc-50">
                                                                Final approved (executed): <b data-testid="final-approved-values">{fmtAdjustments(photographerValuesFor(p) ?? agentValuesFor(p))}</b>
                                                            </p>
                                                        )}
                                                    </div>
                                                )}

                                                {/* Reviewer != agent: approve/reject/modify are photographer-only. */}
                                                {canPhotographerAct && (p.status === 'pending_review' || p.status === 'draft') && (
                                                    <div className="mt-2">
                                                        <div className="flex gap-2">
                                                            <button
                                                                onClick={() => humanApprove(p)}
                                                                disabled={busy !== null}
                                                                className="rounded bg-emerald-500 px-2 py-1 text-xs font-semibold text-zinc-100 hover:bg-emerald-400 disabled:opacity-40"
                                                            >
                                                                Approve
                                                            </button>
                                                            {(p.type === 'retouch' || p.type === 'batch_retouch') && (
                                                                <button
                                                                    onClick={() => setModifyOpenFor((cur) => (cur === p.id ? null : p.id))}
                                                                    disabled={busy !== null}
                                                                    className="rounded border border-amber-400/60 px-2 py-1 text-xs font-semibold text-indigo-400 hover:bg-indigo-500/10 disabled:opacity-40"
                                                                >
                                                                    Modify
                                                                </button>
                                                            )}
                                                            <button
                                                                onClick={() => setRejectTargetId(p.id)}
                                                                disabled={busy !== null}
                                                                className="rounded bg-rose-600 px-2 py-1 text-xs font-semibold text-zinc-100 hover:bg-rose-500 disabled:opacity-40"
                                                            >
                                                                Reject
                                                            </button>
                                                        </div>
                                                        {modifyOpenFor === p.id && (
                                                            <div className="mt-2 rounded-lg border border-indigo-500/30 bg-zinc-900/60 p-2" data-testid="modify-form">
                                                                <p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
                                                                    Your values (agent proposed {fmtAdjustments(agentValuesFor(p))})
                                                                </p>
                                                                <div className="mt-1 flex items-center gap-2">
                                                                    <label htmlFor={`mod-exposure-${p.id}`} className="text-xs text-zinc-300">Exposure</label>
                                                                    <input
                                                                        id={`mod-exposure-${p.id}`}
                                                                        data-testid="modify-exposure"
                                                                        type="number" step="0.01" min="-1" max="1"
                                                                        value={modifyValues.exposure}
                                                                        onChange={(e) => setModifyValues((v) => ({ ...v, exposure: e.target.value }))}
                                                                        placeholder={String(agentValuesFor(p).exposure ?? 0)}
                                                                        className="w-20 rounded border border-zinc-700 px-1.5 py-1 text-xs"
                                                                    />
                                                                    <label htmlFor={`mod-warmth-${p.id}`} className="text-xs text-zinc-300">Warmth</label>
                                                                    <input
                                                                        id={`mod-warmth-${p.id}`}
                                                                        data-testid="modify-warmth"
                                                                        type="number" step="0.01" min="-1" max="1"
                                                                        value={modifyValues.warmth}
                                                                        onChange={(e) => setModifyValues((v) => ({ ...v, warmth: e.target.value }))}
                                                                        placeholder={String(agentValuesFor(p).warmth ?? 0)}
                                                                        className="w-20 rounded border border-zinc-700 px-1.5 py-1 text-xs"
                                                                    />
                                                                    <button
                                                                        onClick={() => void humanModify(p)}
                                                                        disabled={busy !== null}
                                                                        className="rounded bg-amber-400 px-2 py-1 text-xs font-semibold text-zinc-100 hover:bg-amber-400 disabled:opacity-40"
                                                                    >
                                                                        Save values
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        )}
                                                    </div>
                                                )}

                                                {/* Server policy remains the authority for execution. */}
                                                {permissions.can_execute && p.status === 'approved' && !p.executed_at && (
                                                    <button
                                                        onClick={() => setExecuteTargetId(p.id)}
                                                        disabled={busy !== null}
                                                        className="td-press mt-2 w-full rounded-md bg-zinc-800 px-2 py-1.5 text-xs font-semibold text-zinc-100 transition hover:bg-zinc-700 disabled:cursor-not-allowed disabled:opacity-40"
                                                        title="Runs the dynamically-registered apply_approved_plan tool"
                                                    >
                                                        {busy === 'execute' ? (<><span className="td-spinner" aria-hidden="true" /> Executing…</>) : 'Execute Plan'}
                                                    </button>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </div>

                        {/* Agent / Tool Activity */}
                        <div className="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 shadow-sm">
                            <div className="mb-2 flex items-center justify-between">
                                <h3 className="text-sm font-semibold text-zinc-100">Agent Activity</h3>
                                <button onClick={refreshState} disabled={busy !== null} className="text-xs font-medium text-zinc-400 hover:text-zinc-300">
                                    {busy === 'refresh' ? '…' : 'Refresh'}
                                </button>
                            </div>
                            <ul className="max-h-72 space-y-1.5 overflow-y-auto pr-1 text-xs">
                                {localActivity.length === 0 ? (
                                    <li className="text-zinc-400">No agent activity yet.</li>
                                ) : (
                                    localActivity.map((a) => (
                                        <li key={a.id} className="flex items-start gap-2 rounded-md border border-zinc-800/70 p-1.5">
                                            <span className={`mt-1 h-2 w-2 shrink-0 rounded-full ${STATUS_DOT[a.result_status] ?? 'bg-zinc-600'}`} />
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-1.5">
                                                    <code className="rounded bg-zinc-900 px-1 text-xs font-semibold text-zinc-100">{a.tool_name}</code>
                                                    <span className={`rounded-full px-1.5 text-xs font-bold ${AUTHORITY_COLOR[a.authority] ?? 'bg-zinc-900 text-zinc-300'}`}>{a.authority}</span>
                                                </div>
                                                <div className="mt-0.5 text-xs text-zinc-400">{fmtTime(a.created_at)} · {a.result_status}</div>
                                                {a.output_summary && (
                                                    <pre className="mt-1 max-w-full overflow-x-auto rounded bg-zinc-950/40 p-1 text-xs leading-tight text-zinc-500">{JSON.stringify(a.output_summary)}</pre>
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
                <div className="td-fade-up td-delay-4 mt-6 rounded-xl border border-zinc-800 bg-zinc-900/60 shadow-sm">
                    <button
                        onClick={() => setDiagOpen((o) => !o)}
                        className="flex w-full items-center justify-between px-4 py-3 text-left"
                    >
                        <span className="text-sm font-semibold text-zinc-100">
                            WebMCP Diagnostics
                            {snapshot?.webmcpAvailable ? (
                                <span className="ms-2 rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs font-bold text-emerald-400">document.modelContext active</span>
                            ) : (
                                <span className="ms-2 rounded-full bg-amber-400/10 px-2 py-0.5 text-xs font-bold text-amber-400">fallback (no WebMCP API)</span>
                            )}
                        </span>
                        <svg
                            aria-hidden="true"
                            className={`h-4 w-4 shrink-0 text-zinc-400 transition-transform duration-300 ease-out ${diagOpen ? 'rotate-90' : ''}`}
                            viewBox="0 0 20 20"
                            fill="currentColor"
                        >
                            <path fillRule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clipRule="evenodd" />
                        </svg>
                    </button>
                    {diagOpen && (
                        <div className="border-t border-zinc-800/70 px-4 py-3">
                            <div className="mb-2 flex flex-wrap gap-2 text-xs">
                                <span className="rounded bg-zinc-900 px-2 py-1 text-zinc-300">Project #{project.id}</span>
                                <span className={`rounded px-2 py-1 font-medium ${eligibleForExecution ? 'bg-emerald-500/15 text-emerald-400' : 'bg-zinc-900 text-zinc-300'}`}>
                                    eligible_for_execution: {eligibleForExecution ? 'true' : 'false'}
                                </span>
                                <span className="rounded bg-zinc-900 px-2 py-1 text-zinc-300">
                                    using_fallback: {String(snapshot?.usingFallback ?? false)}
                                </span>
                            </div>
                            <table className="w-full text-left text-xs">
                                <thead>
                                    <tr className="border-b border-zinc-800/70 text-xs uppercase tracking-wide text-zinc-400">
                                        <th className="py-1.5 pr-2">Tool name</th>
                                        <th className="py-1.5 pr-2">Authority</th>
                                        <th className="py-1.5 pr-2">Dynamic</th>
                                        <th className="py-1.5 pr-2">Registered at</th>
                                        <th className="py-1.5">Live (API join)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {(snapshot?.registered ?? []).map((t) => (
                                        <tr key={t.name} className="border-b border-zinc-800/70">
                                            <td className="py-1.5 pr-2 font-mono text-xs text-zinc-100">{t.name}</td>
                                            <td className="py-1.5 pr-2">
                                                <span className={`rounded-full px-1.5 py-0.5 text-xs font-bold ${AUTHORITY_COLOR[t.authority] ?? ''}`}>{t.authority}</span>
                                            </td>
                                            <td className="py-1.5 pr-2 text-zinc-500">{t.dynamic ? '●' : '—'}</td>
                                            <td className="py-1.5 pr-2 text-zinc-400">{fmtTime(t.registeredAt)}</td>
                                            <td className="py-1.5 text-zinc-500">{snapshot?.webmcpAvailable ? 'registered' : 'fallback only'}</td>
                                        </tr>
                                    ))}
                                    {snapshot && snapshot.registered.length === 0 && (
                                        <tr><td colSpan={5} className="py-2 text-zinc-400">No tools registered (registry not started).</td></tr>
                                    )}
                                </tbody>
                            </table>
                            <div className="mt-2 text-xs text-zinc-400">
                                apply_approved_plan is registered ONLY while an approved, unexecuted proposal exists — approve a proposal above to watch it appear, execute it to watch it disappear.
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
