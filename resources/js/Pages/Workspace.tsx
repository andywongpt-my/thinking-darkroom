import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import AgentChatPanel from '@/Components/AgentChatPanel';
import DangerButton from '@/Components/DangerButton';
import Modal from '@/Components/Modal';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';
import { useWebmcpRegistry } from '@/webmcp/use-webmcp';
import { webmcpApi } from '@/webmcp/api';
import { autoDismissNotification } from '@/webmcp/notifications';
import { localTime, relativeTime } from '@/webmcp/time';
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
    exposure?: number | null;
    contrast?: number | null;
    analysis?: {
        exposure?: number | null;
        contrast?: number | null;
    } | null;
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
    /** P4 — photos still awaiting VLM evidence after this request's batch. */
    vlm_remaining?: number;
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
    pending_review: 'Proposed',
    approved: 'Approved',
    modified: 'Modified',
    rejected: 'Rejected',
    executed: 'Executed',
};

const AUTHORITY_COLOR: Record<string, string> = {
    READ: 'bg-sky-500/15 text-sky-500',
    ANALYZE: 'bg-violet-500/15 text-violet-500',
    PROPOSE: 'bg-amber-400/10 text-amber-500',
    EXECUTE: 'bg-emerald-500/15 text-emerald-600',
};

const STATUS_DOT: Record<string, string> = {
    completed: 'bg-emerald-500',
    denied: 'bg-rose-500',
    failed: 'bg-rose-500',
    error: 'bg-rose-500',
};

const DECISION_BADGE: Record<string, string> = {
    keep: 'bg-emerald-500/15 text-emerald-600',
    review: 'bg-amber-400/10 text-amber-500',
    reject: 'bg-rose-500/15 text-rose-600',
};

function fmtTime(iso: string | null): string {
    return relativeTime(iso);
}

function fullTime(iso: string | null): string {
    return localTime(iso);
}

/** The server supplies this eligibility bit from both agent boundaries. */
export function canHeartbeatPresence(user: { is_agent: boolean; presence_eligible?: boolean }): boolean {
    return user.is_agent === true && user.presence_eligible === true;
}

export function resetFileInput(input: { value: string } | null): void {
    if (input !== null) {
        input.value = '';
    }
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
                <p className="font-mono text-xs uppercase tracking-[0.2em] text-rose-600">IRREVERSIBLE CUT</p>
                <h2 id="delete-photo-title" className="mt-2 text-xl font-semibold text-zinc-100">
                    Delete photo?
                </h2>
                <p className="mt-3 text-sm leading-relaxed text-zinc-400">
                    Remove <span className="text-zinc-200">{photoName}</span> and its stored derivatives from the darkroom?
                </p>
                <p className="mt-3 text-xs leading-relaxed text-rose-600/80">
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
                <p className="font-mono text-xs uppercase tracking-[0.2em] text-rose-600">{eyebrow}</p>
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
                                    <time className="shrink-0 text-xs text-zinc-500" dateTime={entry.decided_at} title={fullTime(entry.decided_at)}>
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

export function CullingCenterSkeleton() {
    return (
        <div
            data-testid="culling-center-skeleton"
            role="status"
            aria-busy="true"
            aria-label="Loading culling context"
            className="mt-4 space-y-3 rounded-xl border border-zinc-700/80 bg-zinc-950/40 p-4"
        >
            <div className="h-3 w-1/3 animate-pulse rounded bg-zinc-800/70" />
            <div className="space-y-2">
                {[0, 1, 2, 3].map((index) => (
                    <div
                        key={`culling-center-skeleton-${index}`}
                        className={`h-3 animate-pulse rounded bg-zinc-800/70 ${index % 2 === 0 ? 'w-full' : 'w-4/5'}`}
                    />
                ))}
            </div>
        </div>
    );
}

export function CullingLightbox({
    selected,
    comparisonPhoto,
    compareMode,
    onClose,
    onToggleCompare,
}: {
    selected: WorkspacePhoto;
    comparisonPhoto: WorkspacePhoto | null;
    compareMode: boolean;
    onClose: () => void;
    onToggleCompare: () => void;
}) {
    return (
        <div
            data-testid="culling-lightbox"
            role="dialog"
            aria-modal="true"
            aria-label={compareMode && comparisonPhoto ? 'Compare photos' : `Inspect ${selected.filename} at 1:1`}
            onClick={onClose}
            className="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/95 p-4 sm:p-6"
        >
            <div
                className="flex max-h-full w-full max-w-7xl flex-col overflow-y-auto rounded-xl border border-zinc-700 bg-zinc-900 p-4 shadow-2xl sm:p-6"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
                            {compareMode && comparisonPhoto ? 'Compare' : 'Inspect 1:1'}
                        </p>
                        <p className="mt-1 text-sm font-semibold text-zinc-100">{selected.filename}</p>
                    </div>
                    <div className="flex items-center gap-2">
                        {comparisonPhoto && (
                            <button
                                type="button"
                                data-testid="culling-lightbox-compare-toggle"
                                onClick={onToggleCompare}
                                className="td-press rounded-md border border-zinc-700 px-3 py-1.5 text-xs font-semibold text-zinc-200 transition hover:border-zinc-500 hover:bg-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                            >
                                {compareMode ? 'Single view' : 'Compare'}
                            </button>
                        )}
                        <button
                            type="button"
                            data-testid="culling-lightbox-close"
                            aria-label="Close 1:1 inspector"
                            onClick={onClose}
                            className="td-press flex min-h-11 min-w-11 items-center justify-center rounded-md border border-zinc-700 text-xl leading-none text-zinc-300 transition hover:border-zinc-500 hover:bg-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                        >
                            ×
                        </button>
                    </div>
                </div>

                {compareMode && comparisonPhoto ? (
                    <div className="grid min-h-0 grid-cols-1 gap-4 md:grid-cols-2" data-testid="culling-compare-view">
                        {[selected, comparisonPhoto].map((photo) => (
                            <figure key={photo.id} className="min-w-0">
                                <div className="flex min-h-64 items-center justify-center rounded-lg border border-zinc-700 bg-zinc-950/40 p-2">
                                    {photo.url ? (
                                        <img
                                            src={photo.url}
                                            alt={`${photo.filename} original at 1:1`}
                                            className="max-h-[72vh] w-full object-contain"
                                        />
                                    ) : (
                                        <span className="text-sm text-zinc-400">Original image unavailable</span>
                                    )}
                                </div>
                                <figcaption className="mt-2 truncate text-xs font-semibold text-zinc-200" title={photo.filename}>
                                    {photo.filename}
                                </figcaption>
                            </figure>
                        ))}
                    </div>
                ) : (
                    <figure className="min-h-0">
                        <div className="flex min-h-64 items-center justify-center rounded-lg border border-zinc-700 bg-zinc-950/40 p-2">
                            {selected.url ? (
                                <img
                                    src={selected.url}
                                    alt={`${selected.filename} original at 1:1`}
                                    className="max-h-[78vh] w-full object-contain"
                                />
                            ) : (
                                <span className="text-sm text-zinc-400">Original image unavailable</span>
                            )}
                        </div>
                        <figcaption className="mt-2 truncate text-xs font-semibold text-zinc-200" title={selected.filename}>
                            {selected.filename}
                        </figcaption>
                    </figure>
                )}
                <p className="mt-4 text-xs text-zinc-500">Click outside or press Escape to close.</p>
            </div>
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

export function fmtAdjustments(params: Record<string, number> | null | undefined): string {
    if (!params || Object.keys(params).length === 0) return '—';
    return Object.entries(params)
        .map(([key, value]) => `${formatAdjustmentValue(value)} ${key}`)
        .join(' · ');
}

/** B2: a truth card is shown only while its own photo is selected. */
export function retouchCardForSelectedPhoto(card: RetouchCard | null | undefined, photoId: number | null | undefined): RetouchCard | null {
    if (!card || card.photo?.id === null || card.photo?.id === undefined) return null;
    return card.photo.id === photoId ? card : null;
}

/** A11: mirror of Domain::RETOUCH_ADJUSTMENTS for the photographer modify form. */
export const RETOUCH_ADJUSTMENT_KEYS = [
    'exposure',
    'contrast',
    'saturation',
    'warmth',
    'highlight_recovery',
    'shadow_lift',
] as const;

export const RETOUCH_ADJUSTMENT_LABELS: Record<(typeof RETOUCH_ADJUSTMENT_KEYS)[number], string> = {
    exposure: 'Exposure',
    contrast: 'Contrast',
    saturation: 'Saturation',
    warmth: 'Warmth',
    highlight_recovery: 'Highlight recovery',
    shadow_lift: 'Shadow lift',
};

export const ADJUSTMENT_MIN = -1;
export const ADJUSTMENT_MAX = 1;

export interface RetouchProposalDraft {
    exposure: number;
    contrast: number;
}

/** Seed the lightweight agent retouch form from available photo analysis values. */
export function retouchDraftForPhoto(photo: Partial<WorkspacePhoto> | null | undefined): RetouchProposalDraft {
    const valueInRange = (value: number | null | undefined, fallback: number): number => {
        if (value === null || value === undefined || !Number.isFinite(value)) return fallback;
        return Math.min(ADJUSTMENT_MAX, Math.max(ADJUSTMENT_MIN, value));
    };

    return {
        exposure: valueInRange(photo?.analysis?.exposure ?? photo?.exposure, 0.3),
        contrast: valueInRange(photo?.analysis?.contrast ?? photo?.contrast, 0.05),
    };
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

const RETOUCH_ADJUSTMENT_ORDER = [
    'exposure',
    'contrast',
    'saturation',
    'warmth',
    'highlight_recovery',
    'shadow_lift',
];

export function formatAdjustmentValue(value: number): string {
    return `${value >= 0 ? '+' : ''}${value.toFixed(2)}`;
}

export function retouchAdjustmentKeys(
    ...sets: Array<Record<string, number> | null | undefined>
): string[] {
    const keys = new Set<string>();
    sets.forEach((set) => {
        Object.keys(set ?? {}).forEach((key) => keys.add(key));
    });

    return [...keys].sort((left, right) => {
        const leftIndex = RETOUCH_ADJUSTMENT_ORDER.indexOf(left);
        const rightIndex = RETOUCH_ADJUSTMENT_ORDER.indexOf(right);
        const normalizedLeft = leftIndex === -1 ? RETOUCH_ADJUSTMENT_ORDER.length : leftIndex;
        const normalizedRight = rightIndex === -1 ? RETOUCH_ADJUSTMENT_ORDER.length : rightIndex;

        return normalizedLeft === normalizedRight
            ? left.localeCompare(right)
            : normalizedLeft - normalizedRight;
    });
}

function adjustmentLabel(key: string): string {
    return key.replace(/_/g, ' ');
}

function AdjustmentGrid({
    params,
    keys,
    testId,
    prefix,
}: {
    params: Record<string, number> | null;
    keys: string[];
    testId: string;
    prefix: string;
}) {
    return (
        <dl className="mt-3 space-y-2 text-sm" data-testid={testId}>
            {keys.length === 0 ? (
                <div className="text-sm text-zinc-400">No adjustment values recorded.</div>
            ) : (
                keys.map((key) => {
                    const value = params?.[key];
                    return (
                        <div
                            key={key}
                            className="flex items-baseline justify-between gap-4"
                            data-testid={`${prefix}-${key}`}
                        >
                            <dt className="capitalize text-zinc-400">{adjustmentLabel(key)}</dt>
                            <dd className="font-mono text-sm font-semibold tabular-nums text-zinc-100">
                                {typeof value === 'number' && Number.isFinite(value)
                                    ? formatAdjustmentValue(value)
                                    : '—'}
                            </dd>
                        </div>
                    );
                })
            )}
        </dl>
    );
}

export function RetouchTruthCard({ card }: { card: RetouchCard | null }) {
    const retouchRenderFailed = Boolean(card?.executed && !card.derivative?.url);

    if (!card) {
        return (
            <div className="rounded-xl border border-zinc-800 bg-zinc-900/60 p-5 shadow-sm" data-testid="retouch-panel">
                <h3 className="text-base font-semibold text-zinc-100">Retouch truth</h3>
                <p className="mt-2 text-sm leading-relaxed text-zinc-400">
                    No retouch recorded for this photo.
                </p>
            </div>
        );
    }

    const hasExecutedDerivative = Boolean(card.executed && card.derivative?.url);
    const finalParams = card.executed?.params ?? card.derivative?.adjustments ?? (
        card.status === 'approved' ? card.photographer_modification?.adjustments ?? null : null
    );
    const valueKeys = retouchAdjustmentKeys(
        card.agent_original.params,
        card.photographer_modification?.adjustments,
        finalParams,
    );
    const resultLabel = hasExecutedDerivative
        ? 'EXECUTED DERIVATIVE'
        : retouchRenderFailed
            ? 'EXECUTION FAILED'
            : card.status === 'approved'
                ? 'APPROVED PREVIEW'
                : 'PREVIEW';
    const resultDescription = hasExecutedDerivative
        ? 'Executed derivative — approved by photographer.'
        : retouchRenderFailed
            ? 'Execution recorded, but no derivative was stored.'
            : card.status === 'approved'
                ? 'Approved preview — awaiting execution; no derivative has been executed.'
                : card.status === 'modified'
                    ? 'Photographer-modified preview — awaiting photographer approval.'
                    : 'Preview only — awaiting photographer approval.';
    const authorityTone = hasExecutedDerivative
        ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-600'
        : retouchRenderFailed
            ? 'border-rose-500/40 bg-rose-500/10 text-rose-600'
            : 'border-amber-400/40 bg-amber-400/10 text-amber-500';
    const photoName = card.photo?.filename ?? 'selected photo';

    return (
        <div className="rounded-xl border border-zinc-800 bg-zinc-900/60 p-5 shadow-sm" data-testid="retouch-panel">
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="text-base font-semibold text-zinc-100">Retouch truth</h3>
                    <p className="mt-1 text-sm text-zinc-400">{photoName}</p>
                </div>
                <span
                    className={`inline-flex min-h-11 items-center rounded-full border px-3 py-2 text-sm font-semibold ${authorityTone}`}
                    data-testid="retouch-status"
                >
                    {resultLabel}
                </span>
            </div>

            <div className="space-y-5">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2" data-testid="before-after">
                    <figure className="min-w-0">
                        <figcaption className="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-300">
                            ORIGINAL
                        </figcaption>
                        {card.original.url ? (
                            <img
                                src={card.original.url}
                                alt={`${photoName} original`}
                                data-testid="original-image"
                                className="w-full rounded-lg border border-zinc-700 object-cover"
                            />
                        ) : (
                            <div className="flex min-h-40 items-center justify-center rounded-lg border border-dashed border-zinc-700 text-sm text-zinc-400">
                                Original image unavailable
                            </div>
                        )}
                        <p className="mt-2 text-sm text-zinc-400">Source image</p>
                    </figure>

                    <figure className="min-w-0">
                        <figcaption className="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-300">
                            {resultLabel}
                        </figcaption>
                        {card.derivative?.url ? (
                            <img
                                src={card.derivative.url}
                                alt={`${photoName} ${hasExecutedDerivative ? 'executed derivative' : 'approved preview'}`}
                                data-testid="derivative-image"
                                className="w-full rounded-lg border border-zinc-700 object-cover"
                            />
                        ) : retouchRenderFailed ? (
                            <div
                                className="rounded-lg border border-rose-500/40 bg-rose-500/10 px-4 py-5 text-sm leading-relaxed text-rose-700"
                                data-testid="retouch-render-error"
                                role="alert"
                            >
                                Retouch render failed: no approved derivative was stored.
                            </div>
                        ) : (
                            <div
                                className="flex min-h-40 items-center justify-center rounded-lg border border-dashed border-zinc-700 px-4 text-center text-sm leading-relaxed text-zinc-400"
                                data-testid="derivative-placeholder"
                            >
                                {card.status === 'approved'
                                    ? 'Approved preview recorded; awaiting execution.'
                                    : 'No derivative is available before photographer approval.'}
                            </div>
                        )}
                        <p className="mt-2 text-sm font-medium text-zinc-300" data-testid="derivative-state">
                            {hasExecutedDerivative ? '✓ Executed derivative · approved by photographer' : resultDescription}
                        </p>
                    </figure>
                </div>

                {card.agent_original.influenced_by.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2" data-testid="retouch-influenced-by">
                        <span className="text-sm font-semibold uppercase tracking-wide text-zinc-400">Brief influence</span>
                        {card.agent_original.influenced_by.map((dimension) => (
                            <code key={dimension} className="rounded bg-zinc-800/80 px-2 py-1 text-sm font-semibold text-zinc-200">
                                {dimension}
                            </code>
                        ))}
                    </div>
                )}

                <div className="rounded-lg border border-zinc-700/80 bg-zinc-950/40 p-4" data-testid="retouch-value-comparison">
                    <div className="mb-3">
                        <h4 className="text-base font-semibold text-zinc-100">Adjustment values</h4>
                        <p className="mt-1 text-sm text-zinc-400">
                            AI proposal and the values recorded for the photographer-approved result.
                        </p>
                    </div>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <section className="rounded-lg border border-zinc-700 bg-zinc-900/70 p-4" data-testid="layer-agent-original">
                            <h5 className="text-sm font-semibold uppercase tracking-wide text-zinc-300">AI PROPOSAL</h5>
                            <p className="mt-1 text-sm text-zinc-400">Agent-suggested starting values</p>
                            <div data-testid="ai-proposed-values">
                                <AdjustmentGrid
                                    params={card.agent_original.params}
                                    keys={valueKeys}
                                    prefix="ai-adjustment"
                                    testId="ai-adjustment-values"
                                />
                            </div>
                        </section>
                        <section className="rounded-lg border border-zinc-700 bg-zinc-900/70 p-4" data-testid="layer-executed">
                            <h5 className="text-sm font-semibold uppercase tracking-wide text-zinc-100">FINAL APPROVED VALUES</h5>
                            <p className="mt-1 text-sm text-zinc-400">
                                {hasExecutedDerivative
                                    ? 'Values used by the executed derivative.'
                                    : card.status === 'approved'
                                        ? 'Approved values are ready; no derivative has been executed.'
                                        : 'No final values recorded yet.'}
                            </p>
                            <div data-testid="final-approved-values">
                                <AdjustmentGrid
                                    params={finalParams}
                                    keys={valueKeys}
                                    prefix="final-adjustment"
                                    testId="final-adjustment-values"
                                />
                            </div>
                        </section>
                    </div>
                </div>

                {card.photographer_modification?.adjustments && (
                    <div className="rounded-lg border border-indigo-500/30 bg-indigo-500/10 p-4" data-testid="layer-photographer-modified">
                        <h5 className="text-sm font-semibold uppercase tracking-wide text-indigo-700">
                            PHOTOGRAPHER MODIFIED
                        </h5>
                        {card.photographer_modification.note && (
                            <p className="mt-1 text-sm text-zinc-300">{card.photographer_modification.note}</p>
                        )}
                        <p className="mt-2 font-mono text-sm font-semibold tabular-nums text-zinc-100" data-testid="photographer-modified-values">
                            {fmtAdjustments(card.photographer_modification.adjustments)}
                        </p>
                    </div>
                )}

                <p
                    className={`flex items-start gap-2 rounded-lg border px-4 py-3 text-sm font-semibold leading-relaxed ${authorityTone}`}
                    data-testid="human-authority-status"
                    role="status"
                >
                    <span aria-hidden="true" data-testid="approval-check">
                        {hasExecutedDerivative ? '✓' : '!' }
                    </span>
                    <span>{resultDescription}</span>
                </p>

                <details className="rounded-lg border border-zinc-700/80" data-testid="retouch-technical-details">
                    <summary className="flex min-h-11 cursor-pointer items-center px-4 py-3 text-sm font-semibold text-zinc-300 transition-colors hover:bg-zinc-800/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-amber-400/60">
                        Technical details
                    </summary>
                    <div className="space-y-2 border-t border-zinc-700/80 px-4 py-3 text-xs leading-relaxed text-zinc-400" data-testid="retouch-evidence">
                        <p>
                            Original URL: <code className="break-all" data-testid="original-url">{card.original.url ?? '—'}</code>
                        </p>
                        <p>
                            Original SHA-256: <code className="break-all" data-testid="original-sha">{card.original.sha256 ?? '—'}</code>
                        </p>
                        {card.derivative && (
                            <>
                                <p>
                                    Derivative URL: <code className="break-all" data-testid="derivative-url">{card.derivative.url ?? '—'}</code>
                                </p>
                                <p>
                                    Derivative SHA-256: <code className="break-all" data-testid="derivative-sha">{card.derivative.sha256 ?? '—'}</code>
                                </p>
                                <p>
                                    Raw storage path: <code className="break-all">{card.derivative.storage_path}</code>
                                </p>
                                <p className={
                                    card.derivative.sha256 && card.original.sha256 && card.derivative.sha256 !== card.original.sha256
                                        ? 'text-emerald-700'
                                        : 'text-zinc-400'
                                } data-testid="checksum-divergence">
                                    {card.derivative.sha256 && card.original.sha256
                                        ? card.derivative.sha256 !== card.original.sha256
                                            ? '✓ Derivative checksum differs from the original.'
                                            : '✗ Derivative checksum matches the original; inspect.'
                                        : 'Checksum comparison unavailable.'}
                                </p>
                                <p>Derivative provenance: <code>{card.derivative.provenance}</code></p>
                            </>
                        )}
                    </div>
                </details>
            </div>
        </div>
    );
}

/* ---------------- Sprint 3 — context-aware culling constants ---------------- */

/** Recommendation → { label, tone }. Order encodes strength for comparisons. */
export const RECOMMENDATION_META: Record<
    string,
    { label: string; badge: string; rank: number }
> = {
    strong_keep: { label: 'STRONG KEEP', badge: 'bg-emerald-500 text-white', rank: 3 },
    keep: { label: 'KEEP', badge: 'bg-emerald-500/15 text-emerald-600', rank: 2 },
    review: { label: 'REVIEW', badge: 'bg-amber-400/10 text-amber-500', rank: 1 },
    reject_candidate: { label: 'REJECT CANDIDATE', badge: 'bg-rose-500 text-white', rank: 0 },
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

/** Return the next frame in the photo grid, wrapping at either edge. */
export function cullingNavigationTarget(
    photoIds: number[],
    selectedId: number | null,
    direction: 'previous' | 'next',
): number | null {
    if (photoIds.length === 0) return null;

    const currentIndex = selectedId === null ? -1 : photoIds.indexOf(selectedId);
    if (currentIndex === -1) {
        return photoIds[direction === 'next' ? 0 : photoIds.length - 1] ?? null;
    }

    const delta = direction === 'next' ? 1 : -1;
    return photoIds[(currentIndex + delta + photoIds.length) % photoIds.length] ?? null;
}

/** Keep culling shortcuts from firing while an editable control owns focus. */
export function isCullingKeyboardInput(
    target: { tagName?: string; isContentEditable?: boolean } | null,
): boolean {
    const tagName = target?.tagName?.toUpperCase();
    return tagName === 'INPUT' || tagName === 'TEXTAREA' || target?.isContentEditable === true;
}

/** Scroll a linked QA frame into view without assuming a browser during SSR. */
export function scrollToPhotoTile(photoId: number): void {
    if (typeof document === 'undefined') return;

    const tile = document.querySelector(`[data-testid="photo-tile-${photoId}"]`);
    if (tile && typeof tile.scrollIntoView === 'function') {
        tile.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

/** Select a QA-linked frame and bring its grid tile into the photographer's view. */
export function selectPhotoFrame(photoId: number, setSelectedId: (id: number) => void): void {
    setSelectedId(photoId);
    scrollToPhotoTile(photoId);
}

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
    const [retouchDraft, setRetouchDraft] = useState<RetouchProposalDraft>(() => retouchDraftForPhoto(photos[0]));
    const [deletePhotoId, setDeletePhotoId] = useState<number | null>(null);
    const [deletingPhoto, setDeletingPhoto] = useState(false);
    const [rejectTargetId, setRejectTargetId] = useState<number | null>(null);
    const [executeTargetId, setExecuteTargetId] = useState<number | null>(null);
    const [dismissQaTargetId, setDismissQaTargetId] = useState<number | null>(null);
    // A7: proposal whose photographer approval is being revoked.
    const [cancelTargetId, setCancelTargetId] = useState<number | null>(null);
    // B3: executed proposal being reverted.
    const [revertTargetId, setRevertTargetId] = useState<number | null>(null);
    const [localProposals, setLocalProposals] = useState<WorkspaceProposal[]>(proposals);
    const [localActivity, setLocalActivity] = useState<ActivityEntry[]>(activity);
    const [eagerVersion, setEagerVersion] = useState(0);
    const [cullIds, setCullIds] = useState<number[]>([]);
    const [cullRationale, setCullRationale] = useState('Suggested cull (edge/soft focus).');
    const [busy, setBusy] = useState<string | null>(null);
    const [diagOpen, setDiagOpen] = useState(false);
    // U-4/U-5: pulse that opens the agent chat drawer from the presence strip.
    const [chatOpenSignal, setChatOpenSignal] = useState(0);

    /* ---------------- Sprint 4 — retouch / QA / creative memory state ---------------- */

    // B2: retouch truth card follows the currently selected photo. The page
    // controller renders the card for the initially-selected photo; selecting
    // another frame fetches that photo's card (null = no retouch recorded).
    const pageCardForSelection = retouchCardForSelectedPhoto(pageRetouchCard ?? null, selectedId);
    const [remoteRetouchCard, setRemoteRetouchCard] = useState<RetouchCard | null>(null);
    const [retouchCardLoading, setRetouchCardLoading] = useState(false);
    const retouchCard =
        pageCardForSelection
        ?? (remoteRetouchCard && remoteRetouchCard.photo?.id === selectedId ? remoteRetouchCard : null);
    const retouchRenderFailed = Boolean(retouchCard?.executed && !retouchCard.derivative?.url);
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
    const [editingMemoryId, setEditingMemoryId] = useState<number | null>(null);
    const [editingMemoryDraft, setEditingMemoryDraft] = useState('');
    const [allMemoriesOpen, setAllMemoriesOpen] = useState(false);
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
    // A11: per-proposal drafts across ALL six Domain::RETOUCH_ADJUSTMENTS —
    // no cross-proposal carry-over; each proposal starts from a clean draft.
    const [modifyValues, setModifyValues] = useState<Record<number, Record<string, string>>>({});
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
    const selectedCount = photos.filter((p) => p.selection_state === 'selected').length;
    // Demo-chain: newest pending proposal, surfaced as a banner so the
    // approve action is one click away after an agent turn.
    const newestPendingProposal = useMemo(
        () => localProposals.filter((p) => p.status === 'pending_review').sort((a, b) => b.id - a.id)[0] ?? null,
        [localProposals],
    );
    const deleteTarget = photos.find((p) => p.id === deletePhotoId) ?? null;
    const rejectTarget = localProposals.find((p) => p.id === rejectTargetId) ?? null;
    const executeTarget = localProposals.find((p) => p.id === executeTargetId) ?? null;
    const cancelTarget = localProposals.find((p) => p.id === cancelTargetId) ?? null;
    const revertTarget = localProposals.find((p) => p.id === revertTargetId) ?? null;
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
    const [cullingNote, setCullingNote] = useState('');
    const [cullingNoteOpen, setCullingNoteOpen] = useState(false);
    const [overrideNote, setOverrideNote] = useState('');
    const [overrideOpen, setOverrideOpen] = useState(false);
    const [lightboxOpen, setLightboxOpen] = useState(false);
    const [compareMode, setCompareMode] = useState(false);

    const recFor = useMemo(() => {
        const map = new Map<number, CullingRecommendationEntry>();
        (culling?.recommendations ?? []).forEach((r) => map.set(r.photo.id, r));
        return map;
    }, [culling]);

    const selectedRec = selected ? recFor.get(selected.id) ?? null : null;

    const selectedDuplicateGroup = useMemo(
        () => selected
            ? (culling?.context.duplicate_groups ?? []).find((group) => group.photo_ids.includes(selected.id)) ?? null
            : null,
        [culling, selected],
    );
    const comparisonPhoto = useMemo(
        () => selectedDuplicateGroup
            ? photos.find((photo) => photo.id !== selected?.id && selectedDuplicateGroup.photo_ids.includes(photo.id)) ?? null
            : null,
        [photos, selected?.id, selectedDuplicateGroup],
    );

    const closeLightbox = () => {
        setLightboxOpen(false);
        setCompareMode(false);
    };

    const openLightbox = (compare = false) => {
        if (!selected) return;
        setCompareMode(compare && comparisonPhoto !== null);
        setLightboxOpen(true);
    };

    useEffect(() => {
        if (!lightboxOpen) return;

        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') closeLightbox();
        };
        document.addEventListener('keydown', closeOnEscape);
        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [lightboxOpen]);

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

    // B2: fetch the selected photo's retouch card whenever the selection
    // changes. The server-rendered page card already matches the initial
    // selection; this keeps the truth card honest for every other frame.
    // The shared `fetch` (not webmcpApi) hits the web route with the page's
    // CSRF/session, mirroring the other human-authority calls.
    useEffect(() => {
        if (!selectedId) {
            setRemoteRetouchCard(null);
            return;
        }
        let live = true;
        setRetouchCardLoading(true);
        fetch(route('workspace.retouch-card', [project.id, selectedId]), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(async (resp) => {
                if (!live) return;
                const j = await resp.json().catch(() => null);
                if (resp.ok && j && typeof j === 'object' && 'retouch_card' in j) {
                    setRemoteRetouchCard((j as { retouch_card: RetouchCard | null }).retouch_card ?? null);
                } else if (live) {
                    setRemoteRetouchCard(null);
                }
            })
            .catch(() => {
                if (live) setRemoteRetouchCard(null);
            })
            .finally(() => {
                if (live) setRetouchCardLoading(false);
            });
        return () => {
            live = false;
        };
    }, [project.id, selectedId]);

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
            setCullingNote('');
            setCullingNoteOpen(false);
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
                setEagerVersion((version) => version + 1);
            });
        });
        return () => {
            live = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [project.id, photos.length]);

    useEffect(() => {
        const current = photos.find((photo) => photo.id === selectedId);
        setRetouchDraft(retouchDraftForPhoto(
            current ? mergeInspectedPhoto(current, eager.get(current.id)) : null,
        ));
    }, [eagerVersion, selectedId, photos, eager]);

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

    const prependProposal = (p: ProposalPayload['proposal']) => {
        setLocalProposals((cur) => [p as WorkspaceProposal, ...cur]);
    };

    const runProposeCull = async () => {
        if (cullIds.length === 0) {
            setNotify({ kind: 'err', text: 'Select at least one photo (click a photo, then tick the checkbox).' });
            return;
        }
        const rationale = cullRationale.trim() || 'Suggested cull (edge/soft focus).';
        setBusy('cull');
        const res = await webmcpApi.proposeCull(
            project.id,
            cullIds.map((pid) => ({ photo_id: pid, action: 'cull', rationale })),
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
        const draft = retouchDraft;
        if (
            !Number.isFinite(draft.exposure)
            || draft.exposure < ADJUSTMENT_MIN
            || draft.exposure > ADJUSTMENT_MAX
            || !Number.isFinite(draft.contrast)
            || draft.contrast < ADJUSTMENT_MIN
            || draft.contrast > ADJUSTMENT_MAX
        ) {
            setNotify({ kind: 'err', text: 'Exposure and contrast must be numbers between -1 and 1.' });
            return;
        }
        setBusy('retouch');
        const res = await webmcpApi.proposeRetouchPlan(
            project.id,
            [{ photo_id: target.id, action: 'exposure', params: { exposure: draft.exposure, contrast: draft.contrast }, rationale: 'Balanced exposure pass.' }],
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
            // P4 budget loop: each invocation vision-analyzes up to the
            // serverless batch limit; vlm_remaining > 0 → keep going until
            // every photo carries VLM-grade evidence.
            let res = await webmcpApi.analyzeProjectPhotos(project.id);
            let loops = 0;
            while (res.ok && res.data && (res.data as { vlm_remaining?: number }).vlm_remaining !== undefined
                && ((res.data as { vlm_remaining?: number }).vlm_remaining ?? 0) > 0 && loops < 25) {
                loops += 1;
                // Stay clear of the per-minute analysis rate limit when the
                // loop runs fast (e.g. the VLM degraded to instant GD rows).
                await new Promise((resolve) => setTimeout(resolve, 200));
                res = await webmcpApi.analyzeProjectPhotos(project.id);
            }
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
                const remaining = (res.data as { vlm_remaining?: number }).vlm_remaining ?? 0;
                setNotify({
                    kind: 'ok',
                    text: remaining > 0
                        ? `Analysis paused: ${remaining} photo(s) awaiting vision analysis — press Analyze again to continue.`
                        : res.data.refreshed_observations > 0
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
            router.reload({ only: ['photos', 'proposals', 'retouchCard', 'activity', 'decisions', 'qaFindings', 'creativeMemories'] });
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
        const draft = modifyValues[proposal.id] ?? {};
        const adjustments: Record<string, number> = {};
        for (const key of RETOUCH_ADJUSTMENT_KEYS) {
            const raw = draft[key];
            if (raw === undefined || raw.trim() === '') continue;
            const value = Number(raw);
            if (!Number.isFinite(value) || value < -1 || value > 1) {
                setNotify({ kind: 'err', text: `${RETOUCH_ADJUSTMENT_LABELS[key]} must be a number between -1 and 1.` });
                return;
            }
            adjustments[key] = value;
        }
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
                    j.superseding_draft as WorkspaceProposal,
                    ...ps.map((p) => (p.id === proposal.id ? { ...p, status: 'modified' } : p)),
                ]);
                addActivity({ tool_name: 'photographer_modify', authority: 'HUMAN', result_status: 'completed', output_summary: { proposal_id: proposal.id, superseded_by: j.superseding_draft.id, adjustments } });
                setNotify({ kind: 'ok', text: `Values saved. New proposal #${j.superseding_draft.id} is pending your review.` });
                // A11: the submitted proposal's draft is consumed; other
                // proposals keep their own untouched drafts.
                setModifyValues((cur) => {
                    const { [proposal.id]: _consumed, ...rest } = cur;
                    return rest;
                });
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

    /** A7: revoke a photographer approval that has not been executed yet. */
    const humanCancelApproval = async (proposal: WorkspaceProposal) => {
        setBusy('cancel');
        try {
            const resp = await fetch(route('proposals.cancel', [project.id, proposal.id]), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '',
                },
                body: JSON.stringify({ note: 'Photographer revoked approval before execution.' }),
            });
            const j = await resp.json().catch(() => null);
            if (resp.ok && j?.proposal) {
                setLocalProposals((ps) => ps.map((p) => (p.id === proposal.id ? { ...p, status: j.proposal.status ?? 'pending_review' } : p)));
                addActivity({ tool_name: 'photographer_cancel_approval', authority: 'HUMAN', result_status: 'completed', output_summary: { proposal_id: proposal.id, status: j.proposal.status ?? 'pending_review' } });
                setNotify({ kind: 'ok', text: `Approval revoked. Proposal #${proposal.id} is back to pending review.` });
            } else {
                setNotify({ kind: 'err', text: `Cancel approval failed: ${j?.error ?? resp.statusText}` });
            }
        } finally {
            setBusy(null);
        }
    };

    const confirmCancelApproval = () => {
        const target = localProposals.find((proposal) => proposal.id === cancelTargetId) ?? null;
        if (!runAfterConfirmation(target, true, (proposal) => { void humanCancelApproval(proposal); })) {
            setCancelTargetId(null);
        }
    };

    /** B3: mark an executed retouch proposal reverted and restore photo states. */
    const humanRevertExecution = async (proposal: WorkspaceProposal) => {
        setBusy('revert');
        try {
            const resp = await fetch(route('proposals.revert', [project.id, proposal.id]), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '',
                },
                body: JSON.stringify({ note: 'Photographer reverted the execution.' }),
            });
            const j = await resp.json().catch(() => null);
            if (resp.ok && j?.proposal) {
                setLocalProposals((ps) => ps.map((p) => (p.id === proposal.id ? { ...p, status: j.proposal.status ?? p.status } : p)));
                addActivity({
                    tool_name: 'photographer_revert_execution',
                    authority: 'HUMAN',
                    result_status: 'completed',
                    output_summary: { proposal_id: proposal.id, photos_restored: j.photos_restored ?? [] },
                });
                setNotify({ kind: 'ok', text: `Execution reverted. ${j.photos_restored?.length ?? 0} photo state(s) restored; the preview bytes are kept for history.` });
                void refreshState();
            } else {
                setNotify({ kind: 'err', text: `Revert failed: ${j?.error ?? resp.statusText}` });
            }
        } finally {
            setBusy(null);
        }
    };

    const confirmRevertExecution = () => {
        const target = localProposals.find((proposal) => proposal.id === revertTargetId) ?? null;
        if (!runAfterConfirmation(target, true, (proposal) => { void humanRevertExecution(proposal); })) {
            setRevertTargetId(null);
        }
    };

    const confirmQaDismiss = () => {
        const target = qaFindings.find((finding) => finding.id === dismissQaTargetId) ?? null;
        if (!runAfterConfirmation(target, true, (finding) => { void respondQaFinding(finding, 'dismiss'); })) {
            setDismissQaTargetId(null);
        }
    };

    /** HUMAN-ONLY: edit a photographer-authored creative memory lesson inline. */
    const editMemory = async (memoryId: number, lesson: string) => {
        const text = lesson.trim();
        if (text.length < 3) {
            setNotify({ kind: 'err', text: 'A lesson needs at least 3 characters.' });
            return;
        }
        setBusy(`memory-edit-${memoryId}`);
        try {
            const resp = await fetch(route('creative-memory.update', [project.id, memoryId]), {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '',
                },
                body: JSON.stringify({ lesson: text }),
            });
            const j = await resp.json().catch(() => null);
            if (resp.ok && j?.memory) {
                setMemories((ms) => ms.map((m) => (m.id === memoryId ? j.memory : m)));
                addActivity({ tool_name: 'photographer_edit_memory', authority: 'HUMAN', result_status: 'completed', output_summary: { memory_id: memoryId } });
                setNotify({ kind: 'ok', text: 'Lesson updated.' });
            } else {
                setNotify({ kind: 'err', text: `Update failed: ${j?.error ?? resp.statusText}` });
            }
        } finally {
            setBusy(null);
        }
    };

    /** HUMAN-ONLY: delete a photographer-authored creative memory lesson. */
    const deleteMemory = async (memoryId: number) => {
        setBusy(`memory-delete-${memoryId}`);
        try {
            const resp = await fetch(route('creative-memory.destroy', [project.id, memoryId]), {
                method: 'DELETE',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '',
                },
            });
            if (resp.ok) {
                setMemories((ms) => ms.filter((m) => m.id !== memoryId));
                addActivity({ tool_name: 'photographer_delete_memory', authority: 'HUMAN', result_status: 'completed', output_summary: { memory_id: memoryId } });
                setNotify({ kind: 'ok', text: 'Lesson removed from Creative Memory.' });
            } else {
                const j = await resp.json().catch(() => null);
                setNotify({ kind: 'err', text: `Delete failed: ${j?.error ?? resp.statusText}` });
            }
        } finally {
            setBusy(null);
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
        if (!files || files.length === 0) {
            resetFileInput(uploadRef.current);
            return;
        }
        // Client-side contract guard: Vercel hard-caps request bodies at 4.5MB,
        // so oversized selections must be rejected before the edge does (Sol P1).
        const MAX_PER_FILE = 4.3 * 1024 * 1024;
        const MAX_COUNT = 10;
        const oversized = Array.from(files).filter((f) => f.size > MAX_PER_FILE);
        if (oversized.length > 0) {
            setNotify({ kind: 'err', text: `Upload failed: ${oversized[0].name} is ${(oversized[0].size / 1024 / 1024).toFixed(1)}MB. Each file must be under 4.3MB on this deployment.` });
            resetFileInput(uploadRef.current);
            return;
        }
        if (files.length > MAX_COUNT) {
            setNotify({ kind: 'err', text: `Upload failed: up to ${MAX_COUNT} photos per batch.` });
            resetFileInput(uploadRef.current);
            return;
        }
        // C7: server enforces the same 4.3MB AGGREGATE body budget — mirror it
        // client-side so the user learns the total is over before uploading.
        const MAX_TOTAL = 4_300_000;
        const totalBytes = Array.from(files).reduce((sum, f) => sum + f.size, 0);
        if (totalBytes > MAX_TOTAL) {
            setNotify({
                kind: 'err',
                text: `Upload failed: this batch is ${(totalBytes / 1024 / 1024).toFixed(1)}MB — the total request budget is 4.3MB. Upload fewer or smaller files.`,
            });
            resetFileInput(uploadRef.current);
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
                // P1b — upload auto-analysis: the backend already gave every
                // photo a first observation; kick the vision-upgrade loop for
                // whatever exceeded the serverless batch budget.
                const rem = (page.props.vlm_remaining ?? 0) as number;
                if (rem > 0) {
                    void runAnalyze();
                }
            },
            onError: (errors) => {
                setBusy(null);
                const first = errors && typeof errors === 'object'
                    ? (Object.values(errors).find((v): v is string => typeof v === 'string') ?? null)
                    : null;
                setNotify({ kind: 'err', text: first ? `Upload failed: ${first}` : 'Upload failed.' });
            },
            onFinish: () => resetFileInput(uploadRef.current),
        });
        resetFileInput(uploadRef.current);
    };

    const toggleCull = (id: number) => {
        setCullIds((c) => (c.includes(id) ? c.filter((x) => x !== id) : [...c, id]));
    };

    const applyBatchCullingDecision = async (decision: CullingChoice) => {
        const selectedIds = cullIds.filter((id) => photos.some((photo) => photo.id === id));
        if (selectedIds.length === 0) {
            setNotify({ kind: 'err', text: 'Select at least one photo before applying a batch decision.' });
            return;
        }

        setBusy(`batch-cull-${decision}`);
        let succeeded = 0;
        const failedPhotos: string[] = [];

        try {
            for (const photoId of selectedIds) {
                const photoName = photos.find((photo) => photo.id === photoId)?.filename ?? `Photo #${photoId}`;
                try {
                    const result = await recordCullingDecision(photoId, decision);
                    if (result) {
                        succeeded += 1;
                    } else {
                        failedPhotos.push(photoName);
                        setNotify({ kind: 'err', text: `Decision failed for ${photoName}.` });
                    }
                } catch (error) {
                    const reason = error instanceof Error ? error.message : String(error);
                    failedPhotos.push(photoName);
                    setNotify({ kind: 'err', text: `Decision failed for ${photoName}: ${reason}` });
                }
            }

            const failed = selectedIds.length - succeeded;
            const failedSummary = failedPhotos.length > 0 ? ` Failed: ${failedPhotos.join(', ')}.` : '';
            setCullIds([]);
            setNotify({
                kind: failed > 0 ? 'err' : 'ok',
                text: `Applied ${decision.toUpperCase()} to ${succeeded}/${selectedIds.length} selected photo(s).${failed > 0 ? ` ${failed} failed.` : ''}${failedSummary}`,
            });
            router.reload({ only: ['photos', 'proposals', 'retouchCard', 'activity', 'decisions', 'qaFindings', 'creativeMemories'] });
        } finally {
            setBusy(null);
        }
    };

    const handleCullingKeyboard = (event: ReactKeyboardEvent<HTMLDivElement>) => {
        if (isCullingKeyboardInput(event.target as { tagName?: string; isContentEditable?: boolean } | null)) {
            return;
        }

        const photoIds = photos.map((photo) => photo.id);
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            setSelectedId(cullingNavigationTarget(
                photoIds,
                selectedId,
                event.key === 'ArrowLeft' ? 'previous' : 'next',
            ));
            return;
        }

        if (!canPhotographerAct || busy !== null || selectedId === null) return;
        const decision = ({ k: 'keep', r: 'review', x: 'reject' } as Record<string, CullingChoice>)[event.key.toLowerCase()];
        if (!decision) return;

        event.preventDefault();
        void recordCullingDecision(selectedId, decision);
    };

    const webmcpUnavailable = Boolean(snapshot && !snapshot.webmcpAvailable);

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
                        <p className={`text-sm font-semibold ${agentPresence.online ? 'text-emerald-600' : 'text-zinc-200'}`}>
                            {agentPresence.online
                                ? 'Agent online · connected to this workspace'
                                : 'Agent offline · messages still reach the thread'}
                        </p>
                        {agentPresence.online && activeAgentNames && (
                            <p className="mt-0.5 text-xs text-emerald-600">Active agent: {activeAgentNames}</p>
                        )}
                        {!agentPresence.online && lastActiveAt && (
                            <p className="mt-0.5 text-xs text-zinc-500" title={fullTime(lastActiveAt)}>Last active {fmtTime(lastActiveAt)}</p>
                        )}
                    </div>
                    <button
                        type="button"
                        data-testid="agent-presence-open-chat"
                        onClick={() => setChatOpenSignal((value) => value + 1)}
                        className="td-press ml-auto shrink-0 rounded-md border border-zinc-700 px-2.5 py-1.5 text-xs font-semibold text-zinc-200 transition hover:border-zinc-500 hover:bg-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                    >
                        Open chat
                    </button>
                </div>

                {/* WebMCP availability banner */}
                {webmcpUnavailable && (
                    <div className="mb-4 rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-600">
                        <strong>WebMCP is not available in this browser.</strong> The app loads normally, but
                        agent tools will not be registered on <code>document.modelContext</code>.
                    </div>
                )}
                {flash?.success && (
                    <div className="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-600">{flash.success}</div>
                )}
                {notify && (
                    <div role="status" aria-live="polite" data-testid="workspace-notify" className={`td-slide-down mb-4 flex items-start justify-between gap-3 rounded-lg border px-4 py-3 text-sm ${notify.kind === 'ok' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400' : 'border-rose-500/30 bg-rose-500/10 text-rose-600'}`}>
                        <span className="flex items-start gap-2.5">
                            <span aria-hidden="true" className={`mt-0.5 inline-block h-2 w-2 shrink-0 rounded-full ${notify.kind === 'ok' ? 'bg-emerald-400' : 'bg-rose-400'}`} />
                            {notify.text}
                        </span>
                        <button
                            type="button"
                            aria-label="Dismiss notification"
                            onClick={() => setNotify(null)}
                            className="flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded px-1 text-lg leading-none text-zinc-400 transition hover:bg-zinc-800/60 hover:text-zinc-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400"
                        >
                            ×
                        </button>
                    </div>
                )}

                <AgentChatPanel
                    key={project.id}
                    projectId={project.id}
                    currentUser={request.user}
                    canSend={permissions.can_chat ?? false}
                    initialConversation={conversation}
                    presence={agentPresence}
                    openSignal={chatOpenSignal}
                    onAgentTurnComplete={() => router.reload({ only: ['photos', 'proposals', 'retouchCard', 'activity', 'decisions', 'qaFindings', 'creativeMemories'] })}
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

                <WorkspaceConfirmDialog
                    show={cancelTarget !== null}
                    title="Cancel approval?"
                    description={cancelTarget ? `Revoke your approval of proposal #${cancelTarget.id}? It returns to pending review and can no longer be executed until approved again.` : ''}
                    processing={busy === 'cancel'}
                    confirmTestId="workspace-confirm-cancel-approval"
                    eyebrow="PROPOSAL GATE"
                    confirmLabel="Cancel approval"
                    cancelLabel="Keep approval"
                    onClose={() => {
                        if (busy !== 'cancel') setCancelTargetId(null);
                    }}
                    onConfirm={confirmCancelApproval}
                />

                <WorkspaceConfirmDialog
                    show={revertTarget !== null}
                    title="Revert execution?"
                    description={revertTarget ? `Mark proposal #${revertTarget.id} as reverted? Each affected photo's retouch state returns to its pre-execution value; the original files were never modified and preview bytes stay for history.` : ''}
                    processing={busy === 'revert'}
                    confirmTestId="workspace-confirm-revert"
                    eyebrow="REVERT GATE"
                    confirmLabel="Revert execution"
                    cancelLabel="Keep executed"
                    onClose={() => {
                        if (busy !== 'revert') setRevertTargetId(null);
                    }}
                    onConfirm={confirmRevertExecution}
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
                        <div
                            data-testid="culling-keyboard-nav"
                            role="group"
                            tabIndex={0}
                            aria-label="Photo culling grid. Use left and right arrow keys to change the selected photo. Press K to keep, R to review, or X to reject."
                            onKeyDown={handleCullingKeyboard}
                            className="rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 focus-visible:ring-offset-zinc-900"
                        >
                            {!cullingLoading && photos.length > 0 && (
                                <div className="mb-3 space-y-2 rounded-lg border border-zinc-700/80 bg-zinc-950/40 p-2">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="text-xs font-semibold text-zinc-400" aria-live="polite">
                                            {cullIds.length} selected
                                        </span>
                                        <div className="flex flex-wrap gap-1.5">
                                            <button
                                                type="button"
                                                data-testid="culling-select-all"
                                                onClick={() => setCullIds(photos.map((photo) => photo.id))}
                                                disabled={busy !== null}
                                                className="td-press rounded-md border border-zinc-700 bg-zinc-900 px-2 py-1 text-xs font-semibold text-zinc-200 hover:border-zinc-500 hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-40"
                                            >
                                                Select all
                                            </button>
                                            <button
                                                type="button"
                                                data-testid="culling-deselect-all"
                                                onClick={() => setCullIds([])}
                                                disabled={busy !== null || cullIds.length === 0}
                                                className="td-press rounded-md border border-zinc-700 px-2 py-1 text-xs font-semibold text-zinc-400 hover:border-zinc-500 hover:text-zinc-200 disabled:cursor-not-allowed disabled:opacity-40"
                                            >
                                                Deselect all
                                            </button>
                                        </div>
                                    </div>
                                    {canPhotographerAct && (
                                        <div className="flex flex-wrap items-center gap-2 border-t border-zinc-700/70 pt-2" data-testid="culling-batch-actions">
                                            <span className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
                                                Apply to {cullIds.length} selected
                                            </span>
                                            {(['keep', 'review', 'reject'] as CullingChoice[]).map((choice) => (
                                                <button
                                                    key={choice}
                                                    type="button"
                                                    data-testid={`culling-batch-${choice}`}
                                                    aria-label={`Apply ${choice} to ${cullIds.length} selected photos`}
                                                    onClick={() => void applyBatchCullingDecision(choice)}
                                                    disabled={busy !== null || cullIds.length === 0}
                                                    className={`td-press rounded-md border px-2.5 py-1 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-40 ${
                                                        choice === 'keep'
                                                            ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/15'
                                                            : choice === 'reject'
                                                                ? 'border-rose-500/40 bg-rose-500/10 text-rose-600 hover:bg-rose-500/15'
                                                                : 'border-amber-400/40 bg-amber-400/10 text-amber-500 hover:bg-amber-400/20'
                                                    }`}
                                                >
                                                    {busy === `batch-cull-${choice}` ? 'Applying…' : choice.charAt(0).toUpperCase() + choice.slice(1)}
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                    <p className="text-xs text-zinc-500">
                                        Use ←/→ to navigate · K keep · R review · X reject
                                    </p>
                                </div>
                            )}
                            <div
                                className="grid grid-cols-3 gap-2"
                                aria-busy={cullingLoading}
                                aria-label={cullingLoading ? 'Loading photos' : 'Photo thumbnails'}
                            >
                                {cullingLoading ? (
                                    Array.from({ length: 8 }, (_, index) => {
                                        const photo = photos[index];
                                        return (
                                            <div
                                                key={`culling-skeleton-${index}`}
                                                data-testid="culling-photo-skeleton"
                                                className="group relative"
                                                aria-hidden={photo ? undefined : true}
                                            >
                                                <div className="aspect-square w-full animate-pulse rounded-md bg-zinc-800/60" />
                                                {photo && (
                                                    <label className="absolute right-1 top-1 flex min-h-11 min-w-11 cursor-pointer items-center justify-center rounded bg-black/50 text-zinc-100">
                                                        <input
                                                            type="checkbox"
                                                            aria-label={`Select ${photo.filename} for culling`}
                                                            checked={cullIds.includes(photo.id)}
                                                            onChange={() => toggleCull(photo.id)}
                                                            className="td-select h-5 w-5"
                                                        />
                                                    </label>
                                                )}
                                            </div>
                                        );
                                    })
                                ) : photos.length === 0 ? (
                                    <div
                                        className="col-span-3 rounded-lg border border-dashed border-zinc-700 bg-zinc-950/40 px-4 py-8 text-center"
                                        data-testid="photos-empty-state"
                                        role="status"
                                    >
                                        <svg className="mx-auto h-10 w-10 text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M4 16.5V7.75A2.75 2.75 0 0 1 6.75 5h10.5A2.75 2.75 0 0 1 20 7.75v8.5A2.75 2.75 0 0 1 17.25 19H6.75A2.75 2.75 0 0 1 4 16.25v.25Z" />
                                            <path strokeLinecap="round" strokeLinejoin="round" d="m4.5 16 4.25-4.25a1.5 1.5 0 0 1 2.121 0L13 13.879l1.129-1.129a1.5 1.5 0 0 1 2.121 0L20 16.5M15.5 9.5h.01" />
                                        </svg>
                                        <h4 className="mt-3 text-sm font-semibold text-zinc-100">No photos yet</h4>
                                        <p className="mx-auto mt-1 max-w-xs text-xs leading-relaxed text-zinc-400">
                                            Upload the original frames to start culling this project.
                                        </p>
                                        {permissions.can_upload && (
                                            <button
                                                type="button"
                                                data-testid="photos-empty-upload"
                                                onClick={() => uploadRef.current?.click()}
                                                disabled={busy !== null}
                                                className="td-press mt-4 inline-flex items-center gap-1.5 rounded-md bg-zinc-800 px-3 py-2 text-xs font-semibold text-zinc-100 transition hover:bg-zinc-700 disabled:cursor-not-allowed disabled:opacity-40"
                                            >
                                                <span aria-hidden="true">+</span> Upload photos
                                            </button>
                                        )}
                                    </div>
                                ) : photos.map((p) => {
                                    const rec = recFor.get(p.id);
                                    const recMeta = rec ? RECOMMENDATION_META[rec.recommendation] : null;
                                    return (
                                    <div key={p.id} className="group relative" data-testid={`photo-tile-${p.id}`}>
                                        <button
                                            onClick={() => setSelectedId(p.id)}
                                            className={`td-press w-full overflow-hidden rounded-md border-2 transition-all duration-200 hover:border-zinc-500 ${selectedId === p.id ? 'border-amber-500' : 'border-transparent'} ${p.selection_state === 'culled' ? 'opacity-60 grayscale' : ''}`}
                                        >
                                            {p.url ? (
                                                <img src={p.url} alt={p.filename} className="aspect-square w-full object-cover" loading="lazy" />
                                            ) : (
                                                <div className="flex aspect-square w-full items-center justify-center bg-zinc-800 text-xs text-zinc-300">no img</div>
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
                                            <span className="absolute left-1 top-1 rounded bg-rose-600 px-1 text-xs font-bold text-white">CULL</span>
                                        )}
                                        <label className="absolute right-1 top-1 flex min-h-11 min-w-11 cursor-pointer items-center justify-center rounded bg-black/50 text-zinc-100">
                                            <input
                                                type="checkbox"
                                                aria-label={`Select ${p.filename} for culling`}
                                                checked={cullIds.includes(p.id)}
                                                onChange={() => toggleCull(p.id)}
                                                className="td-select h-5 w-5"
                                            />
                                        </label>
                                    </div>
                                    );
                                })}
                            </div>
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
                                            <span className={`rounded-full px-2 py-0.5 font-medium ${selected.selection_state === 'selected' ? 'bg-emerald-500/15 text-emerald-600' : selected.selection_state === 'culled' ? 'bg-rose-500/15 text-rose-600' : 'bg-zinc-900 text-zinc-300'}`}>
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
                                                className="td-press rounded-md border border-rose-500/50 bg-rose-500/10 px-2.5 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-500/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-400 disabled:cursor-not-allowed disabled:opacity-40"
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
                                <div className="mt-3 flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        data-testid="culling-inspect-1-1"
                                        onClick={() => openLightbox()}
                                        className="td-press rounded-md border border-amber-400/50 bg-amber-400/10 px-3 py-1.5 text-xs font-semibold text-amber-500 transition hover:bg-amber-400/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 disabled:opacity-40"
                                    >
                                        Inspect 1:1
                                    </button>
                                    {comparisonPhoto && (
                                        <button
                                            type="button"
                                            data-testid="culling-compare"
                                            onClick={() => openLightbox(true)}
                                            className="td-press rounded-md border border-zinc-700 bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-zinc-200 transition hover:border-zinc-500 hover:bg-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                                        >
                                            Compare
                                        </button>
                                    )}
                                    <span className="text-xs text-zinc-500">Original image · ESC closes inspector</span>
                                </div>

                                {cullingLoading && <CullingCenterSkeleton />}

                                {analysisError && (
                                    <div
                                        role="alert"
                                        data-testid="analysis-error"
                                        className="mt-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-600"
                                    >
                                        <p className="font-medium">Photo analysis failed: {analysisError}</p>
                                        <button
                                            type="button"
                                            onClick={() => setAnalysisRefresh((version) => version + 1)}
                                            disabled={busy !== null}
                                            className="mt-1 rounded border border-rose-500/40 px-2 py-0.5 text-xs font-semibold text-rose-600 hover:bg-rose-500/15 disabled:opacity-40"
                                        >
                                            Retry analysis
                                        </button>
                                    </div>
                                )}

                                {/* ============ Sprint 3 — context-aware culling card ============ */}
                                {selectedRec && !cullingLoading && (
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
                                                    title="Recommendation confidence: never certainty"
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
                                                            title={`Provenance: ${analysis.observation.creative_provenance} . Creative labels come from the documented demo annotation, not from pixel inference`}
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
                                                        Creative context not available for this frame: creative fit cannot be evaluated.
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
                                                                onClick={() => void recordCullingDecision(selected.id, choice, cullingNote.trim() || undefined)}
                                                                disabled={busy !== null}
                                                                className={`rounded-md px-3 py-1.5 text-xs font-semibold transition disabled:opacity-40 ${
                                                                    active
                                                                        ? 'bg-zinc-800 text-zinc-100'
                                                                        : choice === 'keep'
                                                                            ? 'border border-emerald-500/40 bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/15'
                                                                            : choice === 'reject'
                                                                                ? 'border border-rose-500/40 bg-rose-500/10 text-rose-600 hover:bg-rose-500/15'
                                                                                : 'border border-amber-400/40 bg-amber-400/10 text-amber-500 hover:bg-amber-400/20'
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
                                                {!overrideOpen && (
                                                    <details
                                                        className="mt-2 rounded-lg border border-zinc-700/80"
                                                        data-testid="culling-note-details"
                                                        open={cullingNoteOpen}
                                                        onToggle={(event) => setCullingNoteOpen((event.currentTarget as HTMLDetailsElement).open)}
                                                    >
                                                        <summary className="cursor-pointer px-2.5 py-1.5 text-xs font-medium text-zinc-400 hover:text-zinc-200">
                                                            Add note
                                                        </summary>
                                                        <div className="border-t border-zinc-700/80 p-2.5">
                                                            <label htmlFor="culling-decision-note" className="text-xs font-medium text-zinc-300">
                                                                Note for the decision (optional)
                                                            </label>
                                                            <input
                                                                id="culling-decision-note"
                                                                data-testid="culling-decision-note"
                                                                type="text"
                                                                maxLength={2000}
                                                                value={cullingNote}
                                                                onChange={(e) => setCullingNote(e.target.value)}
                                                                placeholder="Why does this frame stay, need review, or leave the set?"
                                                                className="mt-1 w-full rounded-md border border-zinc-700 bg-zinc-950/40 px-2 py-1.5 text-xs text-zinc-100 focus:border-amber-400/60 focus:outline-none"
                                                            />
                                                        </div>
                                                    </details>
                                                )}
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
                                                                    className="rounded-md bg-amber-400 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-300 disabled:opacity-40"
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
                                                        {myDecisions[selected.id].note ? `: "${myDecisions[selected.id].note}"` : ''}
                                                        {' '}· persisted to photographer_decisions.
                                                    </p>
                                                )}
                                            </div>
                                        )}
                                        {isAgent && (
                                            <p className="mt-3 border-t border-zinc-800 pt-2 text-xs italic text-zinc-400" data-testid="agent-no-final-authority">
                                                Agent view: recommendations only. Culling is finalized exclusively by the photographer.
                                            </p>
                                        )}
                                    </div>
                                )}
                                {cullingError && (
                                    <div
                                        role="alert"
                                        data-testid="culling-error"
                                        className="mt-3 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-600"
                                    >
                                        <p className="font-medium">Culling context failed: {cullingError}</p>
                                        <button
                                            type="button"
                                            onClick={() => void loadCulling()}
                                            disabled={busy !== null || cullingLoading}
                                            className="mt-1 rounded border border-rose-500/40 px-2 py-0.5 text-xs font-semibold text-rose-600 hover:bg-rose-500/15 disabled:opacity-40"
                                        >
                                            Retry culling context
                                        </button>
                                    </div>
                                )}
                                {!selectedRec && !cullingLoading && !cullingError && (
                                    <div className="mt-3 rounded-md border border-amber-400/30 bg-amber-400/10 px-3 py-2 text-xs text-amber-500">
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
                                                className="mt-2 rounded border border-amber-400/40 bg-zinc-900/60 px-2 py-1 text-xs font-semibold text-amber-500 hover:bg-amber-400/10 disabled:opacity-40"
                                            >
                                                {busy === 'analyze' ? 'Analyzing…' : 'Analyze Project Photos'}
                                            </button>
                                        )}
                                    </div>
                                )}
                            </>
                        ) : cullingLoading ? (
                            <CullingCenterSkeleton />
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

                        {retouchCardLoading && (
                            <p className="text-xs text-zinc-500" role="status">Checking retouch status…</p>
                        )}
                        <RetouchTruthCard card={retouchCard} />

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
                                                    f.severity === 'info' ? 'bg-sky-500/15 text-sky-600'
                                                        : f.severity === 'low' ? 'bg-amber-400/10 text-amber-500'
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
                                                <span className={`rounded-full px-2 py-0.5 text-xs font-bold ${f.status === 'open' ? 'bg-amber-400/10 text-amber-500' : f.status === 'acknowledged' ? 'bg-sky-500/15 text-sky-500' : 'bg-zinc-800 text-zinc-300'}`} data-testid={`qa-status-${f.id}`}>
                                                    {f.status}
                                                </span>
                                                {f.photo_id !== null && (
                                                    <button
                                                        type="button"
                                                        data-testid={`qa-locate-frame-${f.id}`}
                                                        onClick={() => selectPhotoFrame(f.photo_id!, setSelectedId)}
                                                        className="rounded border border-amber-400/40 px-2 py-0.5 text-xs font-semibold text-amber-500 hover:bg-amber-400/10"
                                                    >
                                                        Locate frame
                                                    </button>
                                                )}
                                                {canPhotographerAct && f.status === 'open' && (
                                                    <div className="flex gap-1.5">
                                                        <button
                                                            onClick={() => void respondQaFinding(f, 'acknowledge')}
                                                            disabled={busy !== null}
                                                            className="rounded border border-sky-500/40 px-2 py-0.5 text-xs font-semibold text-sky-500 hover:bg-sky-500/10 disabled:opacity-40"
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
                                                {isAgent && <span className="text-xs italic text-zinc-400">agent view: QA actions are photographer authority</span>}
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
                                Photographer decision history: explicit lessons you record. Future proposals read them as
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
                                            {editingMemoryId === m.id ? (
                                                <div className="flex flex-col gap-1.5">
                                                    <input
                                                        type="text"
                                                        value={editingMemoryDraft}
                                                        onChange={(e) => setEditingMemoryDraft(e.target.value)}
                                                        className="w-full rounded border border-amber-400/50 bg-zinc-950 px-2 py-1 text-xs text-zinc-100 focus:border-amber-400/70 focus:outline-none"
                                                        aria-label="Edit lesson text"
                                                        data-testid={`memory-edit-input-${m.id}`}
                                                        autoFocus
                                                    />
                                                    <div className="flex gap-1.5">
                                                        <button
                                                            onClick={() => {
                                                                void editMemory(m.id, editingMemoryDraft);
                                                                setEditingMemoryId(null);
                                                                setEditingMemoryDraft('');
                                                            }}
                                                            disabled={busy !== null}
                                                            className="td-press rounded bg-zinc-800 px-2 py-0.5 text-xs font-semibold text-zinc-100 transition hover:bg-zinc-700 disabled:opacity-40"
                                                            data-testid={`memory-edit-save-${m.id}`}
                                                        >
                                                            Save
                                                        </button>
                                                        <button
                                                            onClick={() => {
                                                                setEditingMemoryId(null);
                                                                setEditingMemoryDraft('');
                                                            }}
                                                            className="td-press rounded border border-zinc-700 px-2 py-0.5 text-xs font-semibold text-zinc-400 transition hover:bg-zinc-800/60"
                                                        >
                                                            Cancel
                                                        </button>
                                                    </div>
                                                </div>
                                            ) : (
                                                <div className="flex items-start justify-between gap-2">
                                                    <p className="text-zinc-200">“{m.lesson}”</p>
                                                    {canPhotographerAct && (
                                                        <div className="flex shrink-0 gap-1">
                                                            <button
                                                                onClick={() => {
                                                                    setEditingMemoryId(m.id);
                                                                    setEditingMemoryDraft(m.lesson);
                                                                }}
                                                                disabled={busy !== null}
                                                                className="td-press rounded border border-zinc-700 px-1.5 py-0.5 text-xs font-semibold text-zinc-400 transition hover:bg-zinc-800/60 hover:text-zinc-200 disabled:cursor-not-allowed disabled:opacity-40"
                                                                aria-label={`Edit lesson: ${m.lesson}`}
                                                                data-testid={`memory-edit-${m.id}`}
                                                            >
                                                                Edit
                                                            </button>
                                                            <button
                                                                onClick={() => void deleteMemory(m.id)}
                                                                disabled={busy !== null}
                                                                className="td-press rounded border border-zinc-700 px-1.5 py-0.5 text-xs font-semibold text-zinc-400 transition hover:bg-zinc-800/60 hover:text-zinc-200 disabled:cursor-not-allowed disabled:opacity-40"
                                                                aria-label={`Delete lesson: ${m.lesson}`}
                                                                data-testid={`memory-delete-${m.id}`}
                                                            >
                                                                {busy === `memory-delete-${m.id}` ? '…' : 'Delete'}
                                                            </button>
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                                <p className="mt-0.5 text-xs text-zinc-400" title={fullTime(m.created_at)}>
                                                    {entityName(m.photographer) || 'photographer'} · {m.kind} · {fmtTime(m.created_at)}
                                                </p>
                                        </li>
                                    ))
                                )}
                            </ul>
                            {memories.length >= 30 && (
                                <p className="mt-2 text-xs text-zinc-400" data-testid="memory-truncated-note">
                                    Showing the latest 30 lessons. Older lessons remain active context for future proposals.
                                </p>
                            )}
                            {memories.length > 0 && (
                                <button
                                    onClick={async () => {
                                        if (allMemoriesOpen) {
                                            setAllMemoriesOpen(false);
                                            return;
                                        }
                                        setBusy('memory-index');
                                        try {
                                            const resp = await fetch(route('creative-memory.index', project.id), {
                                                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                                            });
                                            const j = await resp.json().catch(() => null);
                                            if (resp.ok && Array.isArray(j?.memories)) {
                                                setMemories(j.memories);
                                                setAllMemoriesOpen(true);
                                            } else {
                                                setNotify({ kind: 'err', text: 'Could not load all lessons.' });
                                            }
                                        } finally {
                                            setBusy(null);
                                        }
                                    }}
                                    disabled={busy !== null}
                                    className="td-press mt-2 rounded border border-zinc-700 px-2 py-0.5 text-xs font-semibold text-zinc-400 transition hover:bg-zinc-800/60 hover:text-zinc-200 disabled:opacity-40"
                                    data-testid="memory-view-all"
                                >
                                    {busy === 'memory-index' ? 'Loading…' : allMemoriesOpen ? 'Collapse' : 'View all lessons'}
                                </button>
                            )}
                        </div>
                        {/* Agent proposal controls */}
                        <div className="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 shadow-sm">
                            <h3 className="mb-2 text-sm font-semibold text-zinc-100">Agent Proposal</h3>
                            {canPhotographerAct && newestPendingProposal !== null && (
                                <div
                                    className="mb-3 flex items-center justify-between gap-2 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-3 py-2"
                                    data-testid="approve-proposal-banner"
                                >
                                    <p className="text-xs font-medium text-emerald-300">
                                        {TYPE_LABEL[newestPendingProposal.type] ?? newestPendingProposal.type} proposal #{newestPendingProposal.id} is waiting for your approval.
                                    </p>
                                    <button
                                        onClick={() => humanApprove(newestPendingProposal)}
                                        disabled={busy !== null}
                                        className="td-press shrink-0 rounded bg-emerald-500 px-3 py-1.5 text-xs font-semibold text-zinc-950 transition hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40"
                                    >
                                        {busy === 'approve' ? 'Approving…' : 'Approve proposal'}
                                    </button>
                                </div>
                            )}
                            <div className="space-y-2">
                                {isAgent && (
                                    <>
                                        <label className="block text-xs text-zinc-400" htmlFor="cull-rationale">
                                            Cull rationale
                                            <input
                                                id="cull-rationale"
                                                data-testid="cull-rationale"
                                                type="text"
                                                value={cullRationale}
                                                onChange={(e) => setCullRationale(e.target.value)}
                                                className="mt-1 w-full rounded border border-zinc-700 bg-zinc-950/40 px-2 py-1.5 text-xs text-zinc-100 focus:border-amber-400/60 focus:outline-none"
                                            />
                                        </label>
                                        <div className="grid grid-cols-2 gap-2">
                                            <label className="text-xs text-zinc-400" htmlFor="retouch-exposure">
                                                Exposure
                                                <input
                                                    id="retouch-exposure"
                                                    data-testid="retouch-exposure"
                                                    type="number"
                                                    min={ADJUSTMENT_MIN}
                                                    max={ADJUSTMENT_MAX}
                                                    step="0.01"
                                                    value={retouchDraft.exposure}
                                                    onChange={(e) => setRetouchDraft((draft) => ({ ...draft, exposure: Number(e.target.value) }))}
                                                    className="mt-1 w-full rounded border border-zinc-700 bg-zinc-950/40 px-2 py-1.5 text-xs text-zinc-100 focus:border-amber-400/60 focus:outline-none"
                                                />
                                            </label>
                                            <label className="text-xs text-zinc-400" htmlFor="retouch-contrast">
                                                Contrast
                                                <input
                                                    id="retouch-contrast"
                                                    data-testid="retouch-contrast"
                                                    type="number"
                                                    min={ADJUSTMENT_MIN}
                                                    max={ADJUSTMENT_MAX}
                                                    step="0.01"
                                                    value={retouchDraft.contrast}
                                                    onChange={(e) => setRetouchDraft((draft) => ({ ...draft, contrast: Number(e.target.value) }))}
                                                    className="mt-1 w-full rounded border border-zinc-700 bg-zinc-950/40 px-2 py-1.5 text-xs text-zinc-100 focus:border-amber-400/60 focus:outline-none"
                                                />
                                            </label>
                                        </div>
                                        <p className="text-xs text-zinc-500">Adjustment values range from -1 to 1.</p>
                                    </>
                                )}
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
                                    className="td-press flex w-full items-center justify-center gap-2 rounded-md bg-amber-400 px-3 py-2 text-xs font-semibold text-white transition hover:bg-amber-300 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    {busy === 'retouch' ? (<><span className="td-spinner" aria-hidden="true" /> Proposing…</>) : 'Propose Retouch Plan'}
                                </button>
                                {isAgent && (
                                    <button
                                        onClick={runAnalyze}
                                        disabled={busy !== null}
                                        className="td-press flex w-full items-center justify-center gap-2 rounded-md bg-violet-500 px-3 py-2 text-xs font-semibold text-white transition hover:bg-violet-400 disabled:cursor-not-allowed disabled:opacity-40"
                                        title="Persist non-final photo observations before reading recommendations"
                                    >
                                        {busy === 'analyze' ? (<><span className="td-spinner" aria-hidden="true" /> Analyzing…</>) : 'Analyze Project Photos'}
                                    </button>
                                )}
                                <button
                                    onClick={runReview}
                                    disabled={busy !== null || !isAgent || selectedCount === 0}
                                    className="td-press flex w-full items-center justify-center gap-2 rounded-md bg-zinc-700 px-3 py-2 text-xs font-semibold text-zinc-100 transition hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-40"
                                    title={selectedCount === 0 ? 'No selected photos in scope — keep photos first' : undefined}
                                >
                                    {busy === 'review' ? (<><span className="td-spinner" aria-hidden="true" /> Reviewing…</>) : 'Run Consistency Review'}
                                </button>
                                {isAgent && selectedCount === 0 && (
                                    <p className="text-xs text-zinc-400">No selected photos in scope — keep photos first to run a consistency review.</p>
                                )}
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
                                                    <span className={`rounded-full px-2 py-0.5 text-xs font-bold ${p.status === 'approved' ? 'bg-emerald-500/15 text-emerald-600' : p.status === 'executed' ? 'bg-zinc-800 text-zinc-300' : p.status === 'rejected' ? 'bg-rose-500/15 text-rose-600' : 'bg-amber-400/10 text-amber-500'}`}>
                                                        {STATE_LABEL[p.status] ?? p.status}
                                                    </span>
                                                </div>
                                                {p.summary && <p className="mt-1 text-xs text-zinc-500">{p.summary}</p>}
                                                <div className="mt-1 text-xs text-zinc-400" title={fullTime(p.created_at)}>
                                                    {p.items.length === 0 && p.status === 'draft'
                                                        ? '0 item(s): awaiting agent generation'
                                                        : `${p.items.length} item(s)`}
                                                    {' · '}
                                                    {p.created_by ?? 'agent'} · {fmtTime(p.created_at)}
                                                </div>

                                                {/* C5: honest per-proposal layer only. The proposal row
                                                    shows the AI proposal values it actually carries; the
                                                    photographer/executed truth lives on the retouch truth
                                                    card for the selected photo — never a borrowed copy. */}
                                                {(p.type === 'retouch' || p.type === 'batch_retouch') && p.items[0]?.params && (
                                                    <div className="mt-1.5 space-y-0.5 text-xs" data-testid={`proposal-${p.id}-values`}>
                                                        <p className="text-zinc-300">
                                                            AI proposal: <b data-testid="ai-proposed-values">{fmtAdjustments(agentValuesFor(p))}</b>
                                                        </p>
                                                    </div>
                                                )}

                                                {/* Reviewer != agent: approve/reject/modify are photographer-only. */}
                                                {canPhotographerAct
                                                    && (p.status === 'pending_review' || p.status === 'draft')
                                                    && !(p.status === 'draft' && p.items.length === 0)
                                                    && (
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
                                                                className="rounded bg-rose-600 px-2 py-1 text-xs font-semibold text-white hover:bg-rose-500 disabled:opacity-40"
                                                            >
                                                                Reject
                                                            </button>
                                                        </div>
                                                        {modifyOpenFor === p.id && (
                                                            <div className="mt-2 rounded-lg border border-indigo-500/30 bg-zinc-900/60 p-2" data-testid="modify-form">
                                                                <p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
                                                                    Your values (agent proposed {fmtAdjustments(agentValuesFor(p))})
                                                                </p>
                                                                <div className="mt-1 grid grid-cols-2 gap-2">
                                                                    {RETOUCH_ADJUSTMENT_KEYS.map((key) => (
                                                                        <label key={key} htmlFor={`mod-${key}-${p.id}`} className="flex items-center justify-between gap-2 text-xs text-zinc-300">
                                                                            <span>{RETOUCH_ADJUSTMENT_LABELS[key]}</span>
                                                                            <input
                                                                                id={`mod-${key}-${p.id}`}
                                                                                data-testid={`modify-${key}`}
                                                                                type="number" step="0.01" min="-1" max="1"
                                                                                value={(modifyValues[p.id] ?? {})[key] ?? ''}
                                                                                onChange={(e) => setModifyValues((v) => ({ ...v, [p.id]: { ...(v[p.id] ?? {}), [key]: e.target.value } }))}
                                                                                placeholder={String(agentValuesFor(p)[key] ?? 0)}
                                                                                className="w-20 rounded border border-zinc-700 px-1.5 py-1 text-xs"
                                                                            />
                                                                        </label>
                                                                    ))}
                                                                </div>
                                                                <p className="mt-1 text-xs text-zinc-500">All values range from -1 to 1. Leave a field empty to skip it.</p>
                                                                <button
                                                                    onClick={() => void humanModify(p)}
                                                                    disabled={busy !== null}
                                                                    className="td-press mt-2 rounded bg-amber-400 px-2 py-1 text-xs font-semibold text-white hover:bg-amber-300 disabled:opacity-40"
                                                                >
                                                                    Save values
                                                                </button>
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

                                                {/* A7: only the photographer can revoke an approval that has not run. */}
                                                {canPhotographerAct && p.status === 'approved' && !p.executed_at && (
                                                    <button
                                                        onClick={() => setCancelTargetId(p.id)}
                                                        disabled={busy !== null}
                                                        className="td-press mt-2 w-full rounded-md border border-zinc-700 px-2 py-1.5 text-xs font-semibold text-zinc-300 transition hover:border-rose-500/60 hover:text-rose-400 disabled:cursor-not-allowed disabled:opacity-40"
                                                        title="Return this approved proposal to pending review before execution"
                                                    >
                                                        Cancel approval
                                                    </button>
                                                )}

                                                {/* B3: only the photographer can revert an executed retouch plan. */}
                                                {canPhotographerAct && p.status === 'executed' && (p.type === 'retouch' || p.type === 'batch_retouch') && (
                                                    <button
                                                        onClick={() => setRevertTargetId(p.id)}
                                                        data-testid={`proposal-revert-${p.id}`}
                                                        disabled={busy !== null}
                                                        className="td-press mt-2 w-full rounded-md border border-zinc-700 px-2 py-1.5 text-xs font-semibold text-zinc-300 transition hover:border-amber-500/60 hover:text-amber-400 disabled:cursor-not-allowed disabled:opacity-40"
                                                        title="Mark this execution reverted and restore each photo's pre-execution retouch state; preview bytes stay for history"
                                                    >
                                                        {busy === 'revert' ? (<><span className="td-spinner" aria-hidden="true" /> Reverting…</>) : 'Revert execution'}
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
                                                <div className="mt-0.5 text-xs text-zinc-400" title={fullTime(a.created_at)}>{fmtTime(a.created_at)} · {a.result_status}</div>
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

                {lightboxOpen && selected && (
                    <CullingLightbox
                        selected={selected}
                        comparisonPhoto={comparisonPhoto}
                        compareMode={compareMode}
                        onClose={closeLightbox}
                        onToggleCompare={() => setCompareMode((mode) => !mode)}
                    />
                )}

                {/* ============ BOTTOM: WebMCP diagnostics panel ============ */}
                {/* C12: development diagnostics — agent accounts only. */}
                {isAgent && (
                <div className="td-fade-up td-delay-4 mt-6 rounded-xl border border-zinc-800 bg-zinc-900/60 shadow-sm">
                    <button
                        onClick={() => setDiagOpen((o) => !o)}
                        className="flex w-full items-center justify-between px-4 py-3 text-left"
                    >
                        <span className="text-sm font-semibold text-zinc-100">
                            WebMCP Diagnostics
                            {snapshot?.webmcpAvailable ? (
                                <span className="ms-2 rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs font-bold text-emerald-600">document.modelContext active</span>
                            ) : (
                                <span className="ms-2 rounded-full bg-amber-400/10 px-2 py-0.5 text-xs font-bold text-amber-500">fallback (no WebMCP API)</span>
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
                                <span className={`rounded px-2 py-1 font-medium ${eligibleForExecution ? 'bg-emerald-500/15 text-emerald-600' : 'bg-zinc-900 text-zinc-300'}`}>
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
                                            <td className="py-1.5 pr-2 text-zinc-400" title={fullTime(t.registeredAt)}>{fmtTime(t.registeredAt)}</td>
                                            <td className="py-1.5 text-zinc-500">{snapshot?.webmcpAvailable ? 'registered' : 'fallback only'}</td>
                                        </tr>
                                    ))}
                                    {snapshot && snapshot.registered.length === 0 && (
                                        <tr><td colSpan={5} className="py-2 text-zinc-400">No tools registered (registry not started).</td></tr>
                                    )}
                                </tbody>
                            </table>
                            <div className="mt-2 text-xs text-zinc-400">
                                apply_approved_plan is registered ONLY while an approved, unexecuted proposal exists. Approve a proposal above to watch it appear, execute it to watch it disappear.
                            </div>
                        </div>
                    )}
                </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
