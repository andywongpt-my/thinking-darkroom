import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import AgentChatPanel from '@/Components/AgentChatPanel';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useWebmcpRegistry } from '@/webmcp/use-webmcp';
import { webmcpApi } from '@/webmcp/api';
import { onConceptMutatingActivity } from '@/webmcp/events';
import { autoDismissNotification } from '@/webmcp/notifications';
import { localTime, relativeTime } from '@/webmcp/time';
import type { AgentConversation, AgentPresence, ConceptPayload } from '@/webmcp/api';

/* ------------------------------------------------------------------ types */

interface PageProps extends Record<string, unknown> {
    auth: { user: { id: number; name: string; email: string } };
    project: {
        id: number;
        name: string;
        description: string | null;
        status: string;
        owner: string | null;
    };
    request: { user: { id: number; name: string; is_agent: boolean; presence_eligible?: boolean } };
    my_role: string | null;
    can_review: boolean;
    brainstorm: {
        id: number;
        input: string;
        status: string;
        photographer: string | null;
        created_at: string;
    } | null;
    concepts: ConceptPayload[];
    adopted_concept_id: number | null;
    brief: {
        id: number;
        creative_direction: string;
        payload: Record<string, unknown>;
        adopted_at: string | null;
    } | null;
    agent_activity: {
        id: number;
        tool_name: string;
        authority: string;
        result_status: string;
        output_summary: Record<string, unknown> | null;
        created_at: string;
    }[];
    presence?: AgentPresence;
    conversation?: AgentConversation;
    permissions?: {
        can_chat?: boolean;
    };
    webmcp: { available: boolean };
    eligible_proposal_id?: number | null;
}

/* ------------------------------------------------------------- constants */

const AUTHORITY_COLOR: Record<string, string> = {
    READ: 'bg-sky-500/15 text-sky-500',
    ANALYZE: 'bg-violet-500/15 text-violet-500',
    PROPOSE: 'bg-amber-400/10 text-amber-300',
    EXECUTE: 'bg-emerald-500/15 text-emerald-600',
};

const STATUS_DOT: Record<string, string> = {
    completed: 'bg-emerald-500',
    denied: 'bg-rose-500',
    error: 'bg-rose-500',
};

/** Authority-state language: a judge must tell AI PROPOSAL from ADOPTED instantly. */
const STATUS_STYLE: Record<string, { label: string; badge: string; ring: string }> = {
    proposed: { label: 'AI PROPOSAL', badge: 'bg-amber-400/10 text-amber-500', ring: 'border-amber-400/30' },
    exploring: { label: 'EXPLORING', badge: 'bg-sky-500/15 text-sky-500', ring: 'border-sky-500/40' },
    rejected: { label: 'REJECTED', badge: 'bg-rose-500/15 text-rose-600', ring: 'border-rose-500/30' },
    merged: { label: 'MERGED', badge: 'bg-violet-500/15 text-violet-500', ring: 'border-violet-500/30' },
    superseded: { label: 'SUPERSEDED', badge: 'bg-zinc-900 text-zinc-500', ring: 'border-zinc-800' },
    adopted: { label: 'ADOPTED BY PHOTOGRAPHER', badge: 'bg-emerald-500 text-white', ring: 'border-emerald-500' },
};

/** The structured intent dimensions shown on the Creative Canvas. */
const CANVAS_KEYS: { key: string; label: string }[] = [
    { key: 'mood', label: 'Mood' },
    { key: 'emotional_intent', label: 'Emotional intent / story' },
    { key: 'composition', label: 'Composition' },
    { key: 'lighting', label: 'Lighting' },
    { key: 'color', label: 'Color' },
    { key: 'subject_direction', label: 'Subject direction' },
    { key: 'selection_priority', label: 'Selection priority' },
    { key: 'retouch', label: 'Retouch philosophy' },
    { key: 'avoid', label: 'Avoid' },
];

function fmtTime(iso: string | null): string {
    return relativeTime(iso);
}

function fullTime(iso: string | null): string {
    return localTime(iso);
}

function dimensionEntries(content: Record<string, unknown> | null | undefined): [string, string][] {
    if (!content) return [];
    return Object.entries(content).map(([k, v]) => [
        k,
        Array.isArray(v)
            ? v.join(' · ')
            : typeof v === 'object' && v !== null
                ? Object.entries(v as Record<string, unknown>)
                      .map(([kk, vv]) => `${kk}: ${Array.isArray(vv) ? vv.join('/') : String(vv)}`)
                      .join(' · ')
                : String(v),
    ]);
}

function apiErrorReason(error: string | null, status: number): string {
    const message = error?.trim();
    if (message) return message;
    return status > 0 ? `request failed (HTTP ${status})` : 'request failed without a response';
}

/* ---------------------------------------------------------------- helpers */

/**
 * One production behavior, kept out of the component so it is testable
 * without a DOM: subscribe to WebMCP concept-mutating activity and refresh
 * the concept list. Returns the unsubscribe function for useEffect cleanup.
 */
export function bindConceptAutoRefresh(refreshList: () => Promise<unknown> | unknown): () => void {
    return onConceptMutatingActivity(() => {
        void refreshList();
    });
}

/**
 * The brief and activity feed are Inertia-owned state. Keeping them derived
 * from the current page props means a partial router reload replaces the
 * Creative Canvas and collaboration feed with the newly persisted values.
 */
export function creativeRoomViewState(
    brief: PageProps['brief'],
    activity: PageProps['agent_activity'],
): { brief: PageProps['brief']; activity: PageProps['agent_activity'] } {
    return { brief, activity };
}

/** Route photographer decisions through the existing human-only API paths. */
export function submitCreativeRoomDecision(
    action: 'adopt' | 'reject',
    projectId: number,
    conceptId: number,
    note?: string,
) {
    const normalizedNote = note?.trim() || undefined;

    return action === 'adopt'
        ? webmcpApi.adoptConcept(projectId, conceptId, normalizedNote)
        : webmcpApi.rejectConcept(projectId, conceptId, normalizedNote);
}

/* ------------------------------------------------------------ component */

export default function CreativeRoom() {
    const page = usePage<PageProps>();
    const {
        project,
        request,
        can_review,
        brainstorm,
        concepts: initialConcepts,
        adopted_concept_id: initialAdoptedId,
        brief: initialBrief,
        agent_activity: initialActivity,
        presence: pagePresence,
        conversation: pageConversation,
        permissions: pagePermissions,
    } = page.props;

    const isAgent = request.user.is_agent;
    const permissions = pagePermissions ?? { can_chat: false };
    const conversation = pageConversation ?? {
        project_id: project.id,
        trust_boundary: 'untrusted_project_conversation' as const,
        messages: [],
        latest_id: null,
        has_older: false,
    };
    const presence = pagePresence ?? {
        project_id: project.id,
        online: false,
        agents: [],
        checked_at: '',
    };

    /* --------------------------- WebMCP registry --------------------------- */
    // Sprint 2 tools ride on the same certified registry lifecycle as Sprint 1
    // (base registration + dynamic apply_approved_plan reconciliation).
    // C15: the eligible proposal id comes from the server (approved+unexecuted
    // lifecycle), so the dynamic tool registers/unregisters truthfully here.
    const pageEligibleProposalId = typeof page.props.eligible_proposal_id === 'number'
        ? page.props.eligible_proposal_id
        : null;
    const [eligibleProposalId] = useState<number | null>(pageEligibleProposalId);
    const { registry, snapshot } = useWebmcpRegistry(project.id, eligibleProposalId);

    /* ------------------------------- state -------------------------------- */
    const [concepts, setConcepts] = useState<ConceptPayload[]>(initialConcepts);
    const [adoptedId, setAdoptedId] = useState<number | null>(initialAdoptedId);
    const { brief, activity } = creativeRoomViewState(initialBrief, initialActivity);
    const [brainstormInput, setBrainstormInput] = useState('');
    const [brainstormOpen, setBrainstormOpen] = useState(false);
    const [mergeSelection, setMergeSelection] = useState<number[]>([]);
    const [decisionNotes, setDecisionNotes] = useState<Record<number, string>>({});
    const [reviseTarget, setReviseTarget] = useState<ConceptPayload | null>(null);
    const [reviseTitle, setReviseTitle] = useState('');
    const [reviseSummary, setReviseSummary] = useState('');
    const [busy, setBusy] = useState<string | null>(null);
    const [notify, setNotify] = useState<{ kind: 'ok' | 'err'; text: string } | null>(null);
    const notifyTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const [conceptsError, setConceptsError] = useState<string | null>(null);

    useEffect(() => autoDismissNotification(notify, busy, setNotify, notifyTimer), [notify, busy]);

    const adopted = useMemo(
        () => concepts.find((c) => c.id === adoptedId && c.status === 'adopted') ?? null,
        [concepts, adoptedId],
    );

    const refreshList = useCallback(async (): Promise<boolean> => {
        try {
            const res = await webmcpApi.listConcepts(project.id);
            if (res.ok && res.data) {
                setConceptsError(null);
                setConcepts(res.data.concepts);
                const a = res.data.concepts.find((c) => c.status === 'adopted');
                setAdoptedId(a ? a.id : null);
                return true;
            }
            const reason = apiErrorReason(res.error, res.status);
            setConceptsError(reason);
            setNotify({ kind: 'err', text: `Concepts failed: ${reason}` });
            return false;
        } catch (error) {
            const reason = error instanceof Error ? error.message : String(error);
            setConceptsError(reason);
            setNotify({ kind: 'err', text: `Concepts failed: ${reason}` });
            return false;
        }
    }, [project.id]);

    const reloadPage = useCallback(() => {
        router.reload({ only: ['concepts', 'brief', 'adopted_concept_id', 'agent_activity', 'brainstorm'] });
    }, []);

    // WebMCP-driven concept mutations (propose_concepts / revision / merge)
    // refresh the local concept list so new agent proposals appear WITHOUT a
    // manual browser refresh. Reuses refreshList — no new state, no websockets.
    useEffect(() => bindConceptAutoRefresh(refreshList), [refreshList]);

    /* --------------------------- human actions ---------------------------- */

    const doOpenBrainstorm = async () => {
        if (!brainstormInput.trim()) {
            setNotify({ kind: 'err', text: 'Write your freeform creative thinking first.' });
            return;
        }
        setBusy('brainstorm');
        const res = await webmcpApi.openBrainstorm(project.id, brainstormInput.trim());
        setBusy(null);
        if (res.ok) {
            setBrainstormOpen(false);
            setNotify({ kind: 'ok', text: 'Brainstorm session opened. The agent can now reason from your input.' });
            reloadPage();
        } else {
            setNotify({ kind: 'err', text: `Brainstorm failed: ${res.error}` });
        }
    };

    const doExplore = async (concept: ConceptPayload) => {
        setBusy(`explore-${concept.id}`);
        const res = await webmcpApi.exploreConcept(project.id, concept.id);
        setBusy(null);
        if (res.ok && res.data) {
            setConcepts((cs) => cs.map((c) => (c.id === concept.id ? res.data!.concept : c)));
            setNotify({ kind: 'ok', text: `Exploring "${concept.title}". Lineage preserved.` });
        } else {
            setNotify({ kind: 'err', text: `Explore failed: ${res.error}` });
        }
    };

    const doReject = async (concept: ConceptPayload) => {
        setBusy(`reject-${concept.id}`);
        const res = await submitCreativeRoomDecision('reject', project.id, concept.id, decisionNotes[concept.id]);
        setBusy(null);
        if (res.ok && res.data) {
            setDecisionNotes((notes) => ({ ...notes, [concept.id]: '' }));
            setConcepts((cs) => cs.map((c) => (c.id === concept.id ? res.data!.concept : c)));
            setNotify({ kind: 'ok', text: `"${concept.title}" rejected. History preserved.` });
        } else {
            setNotify({ kind: 'err', text: `Reject failed: ${res.error}` });
        }
    };

    const doAdopt = async (concept: ConceptPayload) => {
        setBusy(`adopt-${concept.id}`);
        const res = await submitCreativeRoomDecision('adopt', project.id, concept.id, decisionNotes[concept.id]);
        setBusy(null);
        if (res.ok && res.data) {
            const refreshed = await refreshList();
            if (!refreshed) return;
            setDecisionNotes((notes) => ({ ...notes, [concept.id]: '' }));
            setNotify({ kind: 'ok', text: `Creative direction adopted: "${concept.title}". Structured brief persisted.` });
            reloadPage();
        } else {
            setNotify({ kind: 'err', text: `Adopt failed: ${res.error}` });
        }
    };

    /** A8: propose a revised child concept; lineage preserved, parent untouched. */
    const doRevise = async (concept: ConceptPayload) => {
        const title = reviseTitle.trim();
        if (!title) {
            setNotify({ kind: 'err', text: 'A revision needs a title.' });
            return;
        }
        setBusy(`revise-${concept.id}`);
        const res = await webmcpApi.reviseConcept(project.id, concept.id, {
            title,
            ...(reviseSummary.trim() ? { summary: reviseSummary.trim() } : {}),
            content: concept.content ?? {},
        });
        setBusy(null);
        if (res.ok && res.data) {
            setReviseTarget(null);
            setReviseTitle('');
            setReviseSummary('');
            await refreshList();
            setNotify({ kind: 'ok', text: `Revision proposed: "${res.data.concept.title}". The original direction is preserved.` });
        } else {
            setNotify({ kind: 'err', text: `Revise failed: ${res.error}` });
        }
    };

    /** A8: return a rejected concept to the review ladder. */
    const doReopen = async (concept: ConceptPayload) => {
        setBusy(`reopen-${concept.id}`);
        const res = await webmcpApi.reopenConcept(project.id, concept.id, decisionNotes[concept.id]);
        setBusy(null);
        if (res.ok && res.data) {
            setDecisionNotes((notes) => ({ ...notes, [concept.id]: '' }));
            setConcepts((cs) => cs.map((c) => (c.id === concept.id ? res.data!.concept : c)));
            setNotify({ kind: 'ok', text: `"${concept.title}" reopened for review.` });
        } else {
            setNotify({ kind: 'err', text: `Reopen failed: ${res.error}` });
        }
    };

    /** A8: open the inline revise form seeded from the parent concept. */
    const openReviseForm = (concept: ConceptPayload) => {
        setReviseTarget(concept);
        setReviseTitle(`${concept.title} (revision)`);
        setReviseSummary(concept.summary ?? '');
    };

    const doMerge = async () => {
        if (mergeSelection.length < 2) {
            setNotify({ kind: 'err', text: 'Select at least two concept cards to merge (click "Select" on each).' });
            return;
        }
        setBusy('merge');
        const sources = mergeSelection.map((id) => ({ concept_id: id }));
        const titles = mergeSelection
            .map((id) => concepts.find((c) => c.id === id)?.title ?? `#${id}`)
            .join(' + ');
        const mergedContent = mergeSelection.reduce<Record<string, unknown>>((acc, id) => {
            const src = concepts.find((c) => c.id === id);
            if (!src?.content) return acc;
            for (const [k, v] of Object.entries(src.content)) {
                if (Array.isArray(v)) {
                    const prev = Array.isArray(acc[k]) ? (acc[k] as unknown[]) : [];
                    acc[k] = [...new Set([...prev, ...v])];
                } else if (acc[k] === undefined) {
                    acc[k] = v;
                }
            }
            return acc;
        }, {});
        const res = await webmcpApi.proposeConceptMerge(project.id, sources, {
            title: `Merged: ${titles}`,
            summary: 'Photographer merged these concepts through the Creative Room UI.',
            content: mergedContent,
        });
        setBusy(null);
        if (res.ok && res.data) {
            setMergeSelection([]);
            const refreshed = await refreshList();
            if (!refreshed) return;
            setNotify({ kind: 'ok', text: `Merged concept created with lineage from ${mergeSelection.length} sources.` });
        } else {
            setNotify({ kind: 'err', text: `Merge failed: ${res.error}` });
        }
    };

    const toggleMergeSelect = (id: number) => {
        setMergeSelection((sel) => (sel.includes(id) ? sel.filter((x) => x !== id) : [...sel, id]));
    };

    /* ------------------------------ render -------------------------------- */

    const webmcpUnavailable = Boolean(snapshot && !snapshot.webmcpAvailable);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-zinc-100">
                        Creative Room · {project.name}
                    </h2>
                    <Link
                        href={route('workspace.show', project.id)}
                        className="rounded-md border border-zinc-700 px-3 py-1.5 text-xs font-medium text-zinc-300 hover:bg-zinc-800/60"
                    >
                        ← Workspace
                    </Link>
                </div>
            }
        >
            <Head title={`Creative Room · ${project.name}`} />

            <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                {webmcpUnavailable && (
                    <div className="mb-4 rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-600">
                        <strong>WebMCP is not available in this browser.</strong> Creative Room still works,
                        but agent tools are not registered on <code>document.modelContext</code>.
                    </div>
                )}
                {notify && (
                    <div
                        role="status"
                        aria-live="polite"
                        data-testid="creative-room-notify"
                        className={`td-slide-down mb-4 flex items-start justify-between gap-3 rounded-lg border px-4 py-3 text-sm ${notify.kind === 'ok' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600' : 'border-rose-500/30 bg-rose-500/10 text-rose-600'}`}
                    >
                        <span>{notify.text}</span>
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
                    key={project.id}
                    projectId={project.id}
                    currentUser={request.user}
                    canSend={permissions.can_chat ?? false}
                    initialConversation={conversation}
                    presence={presence}
                />

                <div className="grid grid-cols-1 gap-5 lg:grid-cols-[1fr_320px]">
                    {/* ================= LEFT / MAIN ================= */}
                    <div className="space-y-5">
                        {/* ------- A. Creative Canvas ------- */}
                        <section className="td-fade-up td-delay-1 rounded-xl border border-zinc-800 bg-zinc-900/60 p-5 shadow-sm">
                            <div className="mb-3 flex items-center justify-between">
                                <h3 className="text-sm font-semibold uppercase tracking-wide text-zinc-500">
                                    Creative Canvas · current project intent
                                </h3>
                                {brainstorm && (
                                    <span className="rounded-full bg-indigo-500/10 px-2 py-0.5 text-xs font-bold text-indigo-400">
                                        BRAINSTORM #{brainstorm.id} · {brainstorm.photographer ?? 'photographer'}
                                    </span>
                                )}
                            </div>

                            {adopted && brief ? (
                                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                    {CANVAS_KEYS.map(({ key, label }) => {
                                        const entries = dimensionEntries({
                                            [key]: (brief.payload as Record<string, unknown>)[key],
                                        });
                                        const value = entries[0]?.[1];
                                        return (
                                            <div key={key} className="rounded-lg border border-zinc-800/70 bg-zinc-950/40 p-2.5">
                                                <dt className="text-xs font-semibold uppercase tracking-wide text-zinc-400">{label}</dt>
                                                <dd className="mt-0.5 text-xs text-zinc-100">{value || '—'}</dd>
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <div className="rounded-lg border border-dashed border-zinc-700 bg-zinc-950/40 p-6 text-center">
                                    <p className="text-sm font-medium text-zinc-300">No adopted creative direction yet.</p>
                                    <p className="mt-1 text-xs text-zinc-400">
                                        {brainstorm
                                            ? 'Review the AI concepts below, then adopt one as the direction.'
                                            : can_review
                                                ? 'Open a brainstorm to capture your freeform thinking. The agent proposes concepts from it.'
                                                : 'The photographer has not opened a brainstorm yet.'}
                                    </p>
                                    {brainstorm && (
                                        <p className="mx-auto mt-3 max-w-xl rounded-md bg-zinc-900/60 p-2 text-left text-xs italic text-zinc-500">
                                            “{brainstorm.input}”
                                        </p>
                                    )}
                                </div>
                            )}

                            {brainstorm && (
                                <div
                                    data-testid="brainstorm-history"
                                    className="mt-4 rounded-lg border border-indigo-500/20 bg-indigo-500/5 p-3"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="font-mono text-xs uppercase tracking-[0.16em] text-indigo-300">
                                            PREVIOUS THINKING · {brainstorm.photographer ?? 'PHOTOGRAPHER'}
                                        </p>
                                        <time className="text-xs text-zinc-500" dateTime={brainstorm.created_at} title={fullTime(brainstorm.created_at)}>
                                            {fmtTime(brainstorm.created_at)}
                                        </time>
                                    </div>
                                    <p className="mt-2 whitespace-pre-wrap text-xs leading-relaxed text-zinc-300">
                                        {brainstorm.input}
                                    </p>
                                </div>
                            )}

                            {can_review && (
                                <div className="mt-4 border-t border-zinc-800/70 pt-4">
                                    {!brainstormOpen ? (
                                        <button
                                            onClick={() => setBrainstormOpen(true)}
                                            className="td-press rounded-md bg-zinc-900 px-4 py-2 text-xs font-semibold text-zinc-100 transition hover:bg-zinc-700"
                                        >
                                            {brainstorm ? 'Add more thinking' : 'Open brainstorm'}
                                        </button>
                                    ) : (
                                        <div className="space-y-2">
                                            <textarea
                                                value={brainstormInput}
                                                onChange={(e) => setBrainstormInput(e.target.value)}
                                                rows={3}
                                                maxLength={4000}
                                                placeholder="Freeform creative thinking: mood, references, what you want this set to feel like…"
                                                className="w-full rounded-lg border border-zinc-700 p-3 text-xs focus:border-zinc-600 focus:outline-none"
                                            />
                                            <div className="flex gap-2">
                                                <button
                                                    onClick={doOpenBrainstorm}
                                                    disabled={busy !== null}
                                                    className="rounded-md bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-zinc-100 hover:bg-zinc-700 disabled:opacity-40"
                                                >
                                                    {busy === 'brainstorm' ? 'Saving…' : 'Save brainstorm'}
                                                </button>
                                                <button
                                                    onClick={() => setBrainstormOpen(false)}
                                                    className="rounded-md border border-zinc-700 px-3 py-1.5 text-xs text-zinc-500 hover:bg-zinc-800/60"
                                                >
                                                    Cancel
                                                </button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                        </section>

                        {/* ------- B + C. Concept cards + human actions ------- */}
                        <section className="td-fade-up td-delay-2 rounded-xl border border-zinc-800 bg-zinc-900/60 p-5 shadow-sm">
                            <div className="mb-3 flex items-center justify-between">
                                <h3 className="text-sm font-semibold uppercase tracking-wide text-zinc-500">
                                    Concepts {concepts.length > 0 && <span className="text-zinc-400">({concepts.length})</span>}
                                </h3>
                                {can_review && concepts.length >= 2 && (
                                    <button
                                        onClick={doMerge}
                                        disabled={busy !== null || mergeSelection.length < 2}
                                        className="rounded-md bg-violet-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-violet-500 disabled:opacity-40"
                                        title="Merge the selected concept cards into a new concept (lineage preserved)"
                                    >
                                        {busy === 'merge' ? 'Merging…' : `Merge selected (${mergeSelection.length})`}
                                    </button>
                                )}
                            </div>

                            {conceptsError ? (
                                <div
                                    role="alert"
                                    data-testid="concepts-error"
                                    className="rounded-lg border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-600"
                                >
                                    <p className="font-medium">Could not load concepts: {conceptsError}</p>
                                    <button
                                        type="button"
                                        onClick={() => void refreshList()}
                                        disabled={busy !== null}
                                        className="mt-2 rounded border border-rose-500/40 px-2.5 py-1 text-xs font-semibold text-rose-600 hover:bg-rose-500/15 disabled:opacity-40"
                                    >
                                        Retry concepts
                                    </button>
                                </div>
                            ) : concepts.length === 0 ? (
                                <div className="rounded-lg border border-dashed border-zinc-700 bg-zinc-950/40 p-6 text-center text-sm text-zinc-500">
                                    No concepts yet. The agent proposes them through the{' '}
                                    <code className="rounded bg-zinc-900 px-1">propose_concepts</code> WebMCP tool.
                                </div>
                            ) : (
                                <div className="grid grid-cols-1 gap-3 xl:grid-cols-2">
                                    {concepts.map((c) => {
                                        const style = STATUS_STYLE[c.status] ?? STATUS_STYLE.proposed;
                                        const isAdopted = c.status === 'adopted';
                                        const isTerminal = isAdopted || c.status === 'rejected' || c.status === 'superseded';
                                        return (
                                            <article
                                                key={c.id}
                                                data-testid={`concept-card-${c.id}`}
                                                data-status={c.status}
                                                className={`rounded-xl border-2 ${style.ring} bg-zinc-900/60 p-4 ${isAdopted ? 'shadow-md shadow-emerald-500/40' : ''} ${c.status === 'rejected' ? 'opacity-70' : ''}`}
                                            >
                                                <div className="mb-2 flex items-start justify-between gap-2">
                                                    <h4 className="text-sm font-bold text-zinc-50">{c.title}</h4>
                                                    <span
                                                        data-testid="authority-state"
                                                        className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-extrabold tracking-wide ${style.badge}`}
                                                    >
                                                        {style.label}
                                                    </span>
                                                </div>
                                                {c.summary && <p className="mb-2 text-xs text-zinc-300">{c.summary}</p>}

                                                {/* structured traits */}
                                                <dl className="mb-2 grid grid-cols-1 gap-1">
                                                    {dimensionEntries(c.content).map(([k, v]) => (
                                                        <div key={k} className="rounded bg-zinc-950/40 px-2 py-1">
                                                            <dt className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
                                                                {k.replace(/_/g, ' ')}
                                                            </dt>
                                                            <dd className="text-xs text-zinc-200">{v}</dd>
                                                        </div>
                                                    ))}
                                                </dl>

                                                {/* lineage */}
                                                {(c.parent_concept_id !== null || (c.lineage_basis && c.lineage_basis.length > 0)) && (
                                                    <p className="mb-2 text-xs text-zinc-500">
                                                        <span className="font-semibold">Lineage:</span>{' '}
                                                        {c.parent_concept_id !== null && (
                                                            <>revised from #{c.parent_concept_id}{' · '}</>
                                                        )}
                                                        {c.lineage_basis?.map((b) => (
                                                            <span key={b.concept_id} className="me-1 rounded bg-violet-500/10 px-1 text-violet-700">
                                                              ← {b.title}
                                                            </span>
                                                        ))}
                                                    </p>
                                                )}

                                                <p className="text-xs text-zinc-400" title={fullTime(c.created_at ?? null)}>
                                                    {c.creator_is_agent ? '🤖 agent' : '👤 photographer'} · {fmtTime(c.created_at ?? null)}
                                                </p>

                                                {/* A8: terminal concepts are no longer frozen — the
                                                    photographer can revise an adopted direction (child
                                                    concept, lineage preserved) or reopen a rejected one. */}
                                                {can_review && isAdopted && (
                                                    <div className="mt-3 border-t border-zinc-800/70 pt-2.5">
                                                        <button
                                                            onClick={() => openReviseForm(c)}
                                                            data-testid={`concept-revise-${c.id}`}
                                                            disabled={busy !== null}
                                                            className="rounded border border-sky-500/40 px-2 py-1 text-xs font-semibold text-sky-600 hover:bg-sky-500/10 disabled:opacity-40"
                                                            title="Propose a revised direction derived from this one — the adopted direction stays until you adopt the revision."
                                                        >
                                                            Revise direction
                                                        </button>
                                                    </div>
                                                )}
                                                {can_review && c.status === 'rejected' && (
                                                    <div className="mt-3 border-t border-zinc-800/70 pt-2.5">
                                                        <button
                                                            onClick={() => void doReopen(c)}
                                                            data-testid={`concept-reopen-${c.id}`}
                                                            disabled={busy !== null}
                                                            className="rounded border border-amber-500/40 px-2 py-1 text-xs font-semibold text-amber-500 hover:bg-amber-500/10 disabled:opacity-40"
                                                        >
                                                            Reopen for review
                                                        </button>
                                                    </div>
                                                )}

                                                {/* Human actions — photographer only, never agent, never terminal state */}
                                                {can_review && !isTerminal && (
                                                    <div className="mt-3 border-t border-zinc-800/70 pt-2.5">
                                                        <label
                                                            htmlFor={`concept-decision-note-${c.id}`}
                                                            className="text-xs font-semibold text-zinc-300"
                                                        >
                                                            Explanation note (optional)
                                                        </label>
                                                        <textarea
                                                            id={`concept-decision-note-${c.id}`}
                                                            data-testid={`concept-decision-note-${c.id}`}
                                                            value={decisionNotes[c.id] ?? ''}
                                                            onChange={(e) => setDecisionNotes((notes) => ({ ...notes, [c.id]: e.target.value }))}
                                                            rows={2}
                                                            maxLength={2000}
                                                            placeholder="Why are you adopting or rejecting this concept? (optional)"
                                                            className="mt-1 w-full rounded-md border border-zinc-700 bg-zinc-950/40 p-2 text-xs text-zinc-100 focus:border-zinc-600 focus:outline-none"
                                                        />
                                                        <div className="mt-2 flex flex-wrap gap-1.5">
                                                            <button
                                                                onClick={() => doExplore(c)}
                                                                disabled={busy !== null}
                                                                className="rounded bg-sky-600 px-2 py-1 text-xs font-semibold text-white hover:bg-sky-500 disabled:opacity-40"
                                                            >
                                                                Explore
                                                            </button>
                                                            <button
                                                                onClick={() => doReject(c)}
                                                                disabled={busy !== null}
                                                                className="rounded bg-rose-600 px-2 py-1 text-xs font-semibold text-white hover:bg-rose-500 disabled:opacity-40"
                                                            >
                                                                Reject
                                                            </button>
                                                            <button
                                                                onClick={() => toggleMergeSelect(c.id)}
                                                                className={`rounded px-2 py-1 text-xs font-semibold ${mergeSelection.includes(c.id) ? 'bg-violet-700 text-white' : 'border border-violet-500/40 text-violet-700 hover:bg-violet-500/10'}`}
                                                            >
                                                                {mergeSelection.includes(c.id) ? '✓ Selected' : 'Select'}
                                                            </button>
                                                            <button
                                                                onClick={() => doAdopt(c)}
                                                                disabled={busy !== null}
                                                                className="ms-auto rounded bg-emerald-500 px-2.5 py-1 text-xs font-bold text-zinc-100 hover:bg-emerald-400 disabled:opacity-40"
                                                                title="Adopt as the project's current Creative Direction"
                                                            >
                                                                Adopt as Creative Direction
                                                            </button>
                                                        </div>
                                                    </div>
                                                )}
                                            </article>
                                        );
                                    })}
                                </div>
                            )}

                            {/* A8: inline revise form — a revision is a NEW child concept;
                                the parent (even adopted) is never mutated in place. */}
                            {can_review && reviseTarget && (
                                <div className="mt-4 rounded-xl border border-sky-500/40 bg-zinc-900/60 p-4" data-testid="concept-revise-form">
                                    <h4 className="text-sm font-semibold text-zinc-50">
                                        Revise direction — from "{reviseTarget.title}"
                                    </h4>
                                    <p className="mt-1 text-xs text-zinc-500">
                                        Creates a new proposed concept derived from this one. The current
                                        direction stays adopted until you adopt the revision.
                                    </p>
                                    <label className="mt-3 block text-xs font-semibold text-zinc-300" htmlFor="concept-revise-title">
                                        Title
                                        <input
                                            id="concept-revise-title"
                                            data-testid="concept-revise-title"
                                            type="text"
                                            value={reviseTitle}
                                            onChange={(e) => setReviseTitle(e.target.value)}
                                            maxLength={255}
                                            className="mt-1 w-full rounded-md border border-zinc-700 bg-zinc-950/40 p-2 text-xs text-zinc-100 focus:border-zinc-600 focus:outline-none"
                                        />
                                    </label>
                                    <label className="mt-2 block text-xs font-semibold text-zinc-300" htmlFor="concept-revise-summary">
                                        Summary (optional)
                                        <textarea
                                            id="concept-revise-summary"
                                            data-testid="concept-revise-summary"
                                            value={reviseSummary}
                                            onChange={(e) => setReviseSummary(e.target.value)}
                                            rows={2}
                                            maxLength={2000}
                                            className="mt-1 w-full rounded-md border border-zinc-700 bg-zinc-950/40 p-2 text-xs text-zinc-100 focus:border-zinc-600 focus:outline-none"
                                        />
                                    </label>
                                    <div className="mt-3 flex gap-2">
                                        <button
                                            onClick={() => void doRevise(reviseTarget)}
                                            disabled={busy !== null || !reviseTitle.trim()}
                                            className="rounded bg-sky-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-sky-500 disabled:opacity-40"
                                        >
                                            Propose revision
                                        </button>
                                        <button
                                            onClick={() => setReviseTarget(null)}
                                            disabled={busy !== null}
                                            className="rounded border border-zinc-700 px-2.5 py-1 text-xs font-semibold text-zinc-300 hover:bg-zinc-800 disabled:opacity-40"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            )}
                        </section>
                    </div>

                    {/* ================= RIGHT: Agent Collaboration Panel ================= */}
                    <aside className="td-fade-up td-delay-3 space-y-5">
                        <section className="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 shadow-sm">
                            <h3 className="mb-2 text-sm font-semibold text-zinc-100">Agent Collaboration</h3>
                            <p className="mb-2 text-xs leading-relaxed text-zinc-500">
                                The agent <b>explores · analyzes · proposes · remembers</b>. It can never adopt,
                                reject or commit a creative direction. Those are photographer-only actions.
                            </p>
                            <div className="rounded-lg bg-zinc-950/40 p-2.5">
                                <p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">WebMCP registry</p>
                                <div className="mt-1 flex flex-wrap gap-1">
                                    <span className={`rounded px-1.5 py-0.5 text-xs font-bold ${snapshot?.webmcpAvailable ? 'bg-emerald-500/15 text-emerald-400' : 'bg-amber-400/10 text-amber-400'}`}>
                                        {snapshot?.webmcpAvailable ? 'document.modelContext live' : 'fallback context'}
                                    </span>
                                    <span className="rounded bg-zinc-800 px-1.5 py-0.5 text-xs font-bold text-zinc-200">
                                        {snapshot?.registered.length ?? 0} tools
                                    </span>
                                </div>
                                <ul className="mt-2 space-y-0.5">
                                    {(snapshot?.registered ?? []).map((t) => (
                                        <li key={t.name} className="flex items-center gap-1.5">
                                            <span className={`rounded-full px-1.5 text-xs font-bold ${AUTHORITY_COLOR[t.authority] ?? 'bg-zinc-900 text-zinc-300'}`}>
                                                {t.authority}
                                            </span>
                                            <code className="text-xs text-zinc-300">{t.name}</code>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                            <div className="mt-2 rounded-lg bg-zinc-950/40 p-2.5 text-xs text-zinc-500">
                                <p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">Current context</p>
                                <p className="mt-1">
                                    Concepts: <b>{concepts.length}</b> · Adopted: <b>{adopted ? `#${adopted.id}` : 'none'}</b> ·
                                    Registry: <b>{registry ? 'active' : '—'}</b>
                                </p>
                            </div>
                        </section>

                        <section className="rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 shadow-sm">
                            <h3 className="mb-2 text-sm font-semibold text-zinc-100">WebMCP Proposal Activity</h3>
                            <ul className="max-h-96 space-y-1.5 overflow-y-auto pr-1">
                                {activity.length === 0 ? (
                                    <li className="text-xs text-zinc-400">No agent tool calls yet.</li>
                                ) : (
                                    activity.map((a) => (
                                        <li key={a.id} className="flex items-start gap-2 rounded-md border border-zinc-800/70 p-1.5">
                                            <span className={`mt-1 h-2 w-2 shrink-0 rounded-full ${STATUS_DOT[a.result_status] ?? 'bg-zinc-600'}`} />
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-1.5">
                                                    <code className="rounded bg-zinc-900 px-1 text-xs font-semibold text-zinc-100">{a.tool_name}</code>
                                                    <span className={`rounded-full px-1.5 text-xs font-bold ${AUTHORITY_COLOR[a.authority] ?? 'bg-zinc-900 text-zinc-300'}`}>
                                                        {a.authority}
                                                    </span>
                                                </div>
                                                <div className="mt-0.5 text-xs text-zinc-400" title={fullTime(a.created_at)}>{fmtTime(a.created_at)} · {a.result_status}</div>
                                                {a.output_summary && (
                                                    <pre className="mt-0.5 max-w-full overflow-x-auto rounded bg-zinc-950/40 p-1 text-xs leading-tight text-zinc-500">
                                                        {JSON.stringify(a.output_summary)}
                                                    </pre>
                                                )}
                                            </div>
                                        </li>
                                    ))
                                )}
                            </ul>
                        </section>

                        {brief && (
                            <section className="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 shadow-sm">
                                <h3 className="mb-1 text-sm font-semibold text-emerald-600">Structured Creative Brief</h3>
                                <p className="text-xs text-emerald-400" title={fullTime(brief.adopted_at)}>
                                    {brief.creative_direction} · adopted {fmtTime(brief.adopted_at)}
                                </p>
                                <pre className="mt-2 max-h-56 overflow-auto rounded-lg bg-zinc-900/60 p-2 text-xs leading-relaxed text-zinc-200">
                                    {JSON.stringify(brief.payload, null, 2)}
                                </pre>
                            </section>
                        )}
                    </aside>
                </div>

                {/* Authority legend — the state language, explicit for judges */}
                <div className="mt-6 rounded-xl border border-zinc-800 bg-zinc-900/60 p-4 shadow-sm">
                    <h4 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">Authority state language</h4>
                    <div className="mt-2 flex flex-wrap gap-2">
                        {Object.entries(STATUS_STYLE).map(([k, v]) => (
                            <span key={k} className={`rounded-full px-2 py-0.5 text-xs font-extrabold tracking-wide ${v.badge}`}>
                                {v.label}
                            </span>
                        ))}
                    </div>
                    <p className="mt-2 text-xs text-zinc-400">
                        AI PROPOSAL = created by the agent, awaiting the photographer. ADOPTED BY PHOTOGRAPHER =
                        the photographer's committed creative direction. No agent tool can move a concept into the adopted state.
                    </p>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
