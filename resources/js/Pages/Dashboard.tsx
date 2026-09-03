import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DangerButton from '@/Components/DangerButton';
import Dropdown from '@/Components/Dropdown';
import Modal from '@/Components/Modal';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { PageProps } from '@/types';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

interface DashboardProject {
    id: number;
    name: string;
    description?: string | null;
    status: string;
    photo_count: number;
    pending_proposals: number;
    url: string;
}

type ProjectMeta = {
    description?: string | null;
    can_manage?: boolean;
    approved_proposals?: number;
    executed_proposals?: number;
    last_photo_at?: string | null;
};

interface DashboardTools {
    total: number;
    byAuthority: { READ: number; ANALYZE: number; PROPOSE: number; EXECUTE: number };
    dynamic: { name: string; description: string } | null;
}

interface DashboardAgent {
    name: string | null;
    online: boolean;
    last_seen_at: string | null;
}

type DashboardProps = PageProps<{
    projects: DashboardProject[];
    can_create_project?: boolean;
    project_meta?: Record<string, ProjectMeta>;
    tools?: DashboardTools;
    agent?: DashboardAgent;
    now?: string;
}>;

/* ------------------------------------------------------------------ */
/* Darkroom tokens — charcoal canvas, single amber safelight accent.  */
/* Film-safe light is what preserves negatives; PROPOSAL is what      */
/* preserves photographer authority. The metaphor is the product.     */
/* ------------------------------------------------------------------ */

const STATUS_LABEL: Record<string, string> = {
    active: 'IN PROGRESS',
    archived: 'ARCHIVED',
};

function relativeTime(iso: string | null | undefined): string {
    if (!iso) return '';
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return '';
    const diff = Date.now() - then;
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins} min ago`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 30) return `${days}d ago`;
    return new Date(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function SprocketStrip({ animate = false }: { animate?: boolean }) {
    return (
        <div aria-hidden="true" className={`flex items-center justify-between px-2 py-1.5 ${animate ? 'transition-transform duration-500 ease-out group-hover:translate-x-2' : ''}`}>
            {Array.from({ length: 10 }).map((_, i) => (
                <span key={i} className="h-1.5 w-2.5 rounded-[2px] bg-zinc-700" />
            ))}
        </div>
    );
}

function ToolLadder({ tools }: { tools: DashboardTools }) {
    const rungs: Array<{ label: string; count: number; note: string; tone: string }> = [
        { label: 'READ', count: tools.byAuthority.READ ?? 0, note: 'observe pixels, never change them', tone: 'text-zinc-400' },
        { label: 'ANALYZE', count: tools.byAuthority.ANALYZE ?? 0, note: 'compute judgment, hold it', tone: 'text-zinc-400' },
        { label: 'PROPOSE', count: tools.byAuthority.PROPOSE ?? 0, note: 'recommend. Human decides.', tone: 'text-zinc-400' },
        { label: 'EXECUTE', count: tools.byAuthority.EXECUTE ?? 0, note: 'exists only after approval', tone: 'text-amber-400/90' },
    ];

    return (
        <section aria-label="Agent authority model" className="td-fade-up td-delay-3 rounded-xl border border-zinc-800 bg-zinc-900/60">
            <div className="flex items-baseline justify-between border-b border-zinc-800 px-5 py-4">
                <h2 className="text-sm font-semibold text-zinc-200">Agent authority ladder</h2>
                <span className="font-mono text-xs text-zinc-500" data-testid="dashboard-tool-count">
                    {tools.total} tools
                </span>
            </div>
            <ol className="divide-y divide-zinc-800/70">
                {rungs.map((r) => (
                    <li key={r.label} className="flex items-center gap-4 px-5 py-3 transition-colors duration-200 hover:bg-zinc-800/60">
                        <span className="w-20 font-mono text-sm font-medium text-zinc-200">{r.label}</span>
                        <span className="font-mono text-sm tabular-nums text-zinc-300">{String(r.count).padStart(2, '0')}</span>
                        <span className={`text-xs ${r.tone}`}>{r.note}</span>
                    </li>
                ))}
            </ol>
            {tools.dynamic && (
                <p className="border-t border-zinc-800 px-5 py-3 text-xs leading-relaxed text-zinc-400">
                    <span className="font-mono text-amber-400/90">{tools.dynamic.name}</span>{' '}
                    is registered the moment you approve a proposal, and unregistered the instant it runs.
                    An agent cannot execute what you never approved, because the tool does not exist yet.
                </p>
            )}
        </section>
    );
}

function AgentPresencePanel({ agent }: { agent: DashboardAgent }) {
    return (
        <section aria-label="Agent presence" className="rounded-xl border border-zinc-800 bg-zinc-900/60 px-5 py-4">
            <div className="flex items-center gap-2.5">
                <span
                    data-testid="dashboard-agent-status"
                    className={`h-2 w-2 rounded-full ${agent.online ? 'td-live-dot bg-amber-400' : 'bg-zinc-600'}`}
                />
                <span className="text-sm font-medium text-zinc-200">Studio agent</span>
                <span className={`ml-auto font-mono text-xs ${agent.online ? 'text-amber-400/90' : 'text-zinc-500'}`}>
                    {agent.online ? 'ONLINE' : 'OFFLINE'}
                </span>
            </div>
            <p className="mt-2 text-xs leading-relaxed text-zinc-500">
                {agent.name ? (
                    <>
                        {agent.name} works inside this workspace under WebMCP tool rules.
                        {agent.last_seen_at && <> Last seen {relativeTime(agent.last_seen_at)}.</>}
                    </>
                ) : (
                    'No agent has joined your projects yet.'
                )}
            </p>
        </section>
    );
}

function FilmProjectCard({ p, meta, canManage }: { p: DashboardProject; meta?: ProjectMeta; canManage: boolean }) {
    const executed = meta?.executed_proposals ?? 0;
    const approved = meta?.approved_proposals ?? 0;
    const description = p.description ?? meta?.description ?? '';
    const [showRename, setShowRename] = useState(false);
    const [showDelete, setShowDelete] = useState(false);
    const [showInviteAgent, setShowInviteAgent] = useState(false);
    const [inviteFeedback, setInviteFeedback] = useState<string | null>(null);
    const [deleting, setDeleting] = useState(false);
    const nameInput = useRef<HTMLInputElement>(null);
    const descriptionInput = useRef<HTMLTextAreaElement>(null);
    const inviteEmailInput = useRef<HTMLInputElement>(null);
    const renameTrigger = useRef<HTMLButtonElement>(null);
    const inviteTrigger = useRef<HTMLButtonElement>(null);
    const { data, setData, patch, errors, processing, reset, clearErrors } = useForm({
        name: p.name,
        description,
    });
    const {
        data: inviteData,
        setData: setInviteData,
        post: postInvite,
        errors: inviteErrors,
        processing: inviteProcessing,
        reset: resetInvite,
        clearErrors: clearInviteErrors,
    } = useForm({ email: '' });

    useEffect(() => {
        if (showRename) {
            nameInput.current?.focus();
        }
    }, [showRename]);

    useEffect(() => {
        if (showInviteAgent) {
            inviteEmailInput.current?.focus();
        }
    }, [showInviteAgent]);

    const openRename = () => {
        clearErrors();
        setData('name', p.name);
        setData('description', description);
        setShowRename(true);
    };

    const closeRename = () => {
        if (processing) {
            return;
        }

        reset();
        clearErrors();
        setShowRename(false);
        renameTrigger.current?.focus();
    };

    const openInviteAgent = () => {
        clearInviteErrors();
        setInviteData('email', '');
        setInviteFeedback(null);
        setShowInviteAgent(true);
    };

    const closeInviteAgent = () => {
        if (inviteProcessing) {
            return;
        }

        resetInvite();
        clearInviteErrors();
        setShowInviteAgent(false);
        inviteTrigger.current?.focus();
    };

    const submitInviteAgent: FormEventHandler<HTMLFormElement> = (event) => {
        event.preventDefault();
        postInvite(route('projects.agents.store', p.id), {
            preserveScroll: true,
            onSuccess: () => {
                resetInvite();
                clearInviteErrors();
                setShowInviteAgent(false);
                setInviteFeedback('Agent invitation saved.');
                inviteTrigger.current?.focus();
            },
            onError: () => {
                inviteEmailInput.current?.focus();
            },
        });
    };

    const submitRename: FormEventHandler<HTMLFormElement> = (event) => {
        event.preventDefault();
        patch(route('projects.update', p.id), {
            preserveScroll: true,
            onSuccess: () => {
                setShowRename(false);
                clearErrors();
                renameTrigger.current?.focus();
            },
            onError: (validationErrors) => {
                focusFirstProjectField(validationErrors, nameInput, descriptionInput);
            },
        });
    };

    const toggleArchive = () => {
        router.patch(
            route('projects.update', p.id),
            {
                name: p.name,
                description,
                status: p.status === 'archived' ? 'active' : 'archived',
            },
            { preserveScroll: true },
        );
    };

    const confirmDelete = () => {
        setDeleting(true);
        router.delete(route('projects.destroy', p.id), {
            preserveScroll: true,
            onSuccess: () => setShowDelete(false),
            onFinish: () => setDeleting(false),
        });
    };

    return (
        <div
            data-testid={`dashboard-project-${p.id}`}
            className="td-fade-up group relative overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/60 transition duration-200 hover:border-amber-400/40 hover:shadow-lg hover:shadow-lg hover:shadow-zinc-950/10"
        >
            {canManage && (
                <div className="absolute right-4 top-10 z-30">
                    <Dropdown>
                        <Dropdown.Trigger>
                            <button
                                ref={renameTrigger}
                                type="button"
                                aria-label={`Manage ${p.name}`}
                                aria-haspopup="menu"
                                data-testid={`dashboard-project-${p.id}-actions`}
                                className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-zinc-700/80 bg-zinc-900/60 text-lg leading-none text-zinc-400 transition hover:border-amber-400/50 hover:text-amber-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                            >
                                <span aria-hidden="true">⋮</span>
                            </button>
                        </Dropdown.Trigger>
                        <Dropdown.Content width="48" contentClasses="bg-zinc-900 py-1">
                            <button
                                type="button"
                                data-testid={`dashboard-project-${p.id}-rename`}
                                onClick={openRename}
                                className="td-press block w-full px-4 py-2 text-start text-sm text-zinc-300 transition hover:bg-zinc-800 hover:text-zinc-100 focus:bg-zinc-800 focus:outline-none"
                            >
                                Rename
                            </button>
                            <button
                                ref={inviteTrigger}
                                type="button"
                                data-testid={`dashboard-project-${p.id}-invite-agent`}
                                aria-haspopup="dialog"
                                aria-expanded={showInviteAgent}
                                onClick={openInviteAgent}
                                className="td-press block w-full px-4 py-2 text-start text-sm text-zinc-300 transition hover:bg-zinc-800 hover:text-zinc-100 focus:bg-zinc-800 focus:outline-none"
                            >
                                Invite an agent
                            </button>
                            <button
                                type="button"
                                data-testid={`dashboard-project-${p.id}-archive`}
                                onClick={toggleArchive}
                                className="td-press block w-full px-4 py-2 text-start text-sm text-zinc-300 transition hover:bg-zinc-800 hover:text-zinc-100 focus:bg-zinc-800 focus:outline-none"
                            >
                                {p.status === 'archived' ? 'Unarchive' : 'Archive'}
                            </button>
                            <button
                                type="button"
                                data-testid={`dashboard-project-${p.id}-delete`}
                                onClick={() => setShowDelete(true)}
                                className="td-press block w-full px-4 py-2 text-start text-sm text-rose-300 transition hover:bg-rose-950/50 hover:text-rose-200 focus:bg-rose-950/50 focus:outline-none"
                            >
                                Delete
                            </button>
                        </Dropdown.Content>
                    </Dropdown>
                </div>
            )}

            <Link
                href={p.url}
                data-testid={`dashboard-project-${p.id}-link`}
                className="group block focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400/60 focus-visible:ring-inset"
            >
                {/* top sprocket strip */}
                <div className="border-b border-zinc-800 bg-zinc-950/40">
                    <SprocketStrip animate />
                </div>

                <div className="p-5">
                    <div className="flex items-center gap-3 pr-10">
                        <span
                            className={`h-1.5 w-1.5 shrink-0 rounded-full ${
                                p.pending_proposals > 0 ? 'animate-pulse bg-amber-400' : 'bg-zinc-600'
                            }`}
                            aria-hidden="true"
                        />
                        <h3 className="min-w-0 truncate text-base font-semibold text-zinc-100">{p.name}</h3>
                        <span className="ml-auto shrink-0 font-mono text-xs tracking-[0.18em] text-zinc-500">
                            {STATUS_LABEL[p.status] ?? p.status.toUpperCase()}
                        </span>
                    </div>

                    <div className="mt-5 grid grid-cols-3 gap-2 text-center" data-testid={`dashboard-project-${p.id}-stats`}>
                        <div className="rounded-lg border border-zinc-800/80 bg-zinc-950/40 py-2.5 transition-colors duration-200 group-hover:border-zinc-700">
                            <dd className="text-lg font-semibold tabular-nums text-zinc-100">{p.photo_count}</dd>
                            <dt className="mt-0.5 text-xs text-zinc-500">photos</dt>
                        </div>
                        <div className="rounded-lg border border-zinc-800/80 bg-zinc-950/40 py-2.5 transition-colors duration-200 group-hover:border-zinc-700">
                            <dd
                                className={`text-lg font-semibold tabular-nums ${
                                    p.pending_proposals > 0 ? 'text-amber-400' : 'text-zinc-100'
                                }`}
                            >
                                {p.pending_proposals}
                            </dd>
                            <dt className="mt-0.5 text-xs text-zinc-500">awaiting you</dt>
                        </div>
                        <div className="rounded-lg border border-zinc-800/80 bg-zinc-950/40 py-2.5 transition-colors duration-200 group-hover:border-zinc-700">
                            <dd className="text-lg font-semibold tabular-nums text-zinc-100">{executed}</dd>
                            <dt className="mt-0.5 text-xs text-zinc-500">executed</dt>
                        </div>
                    </div>

                    {(approved > 0 || executed > 0) && (
                        <p className="mt-3 text-xs text-zinc-600">
                            {executed} executed · {approved} approved awaiting run
                        </p>
                    )}

                    <div className="mt-4 flex items-center justify-between">
                        <span className="text-xs text-zinc-500">
                            {meta?.last_photo_at ? `Last upload ${relativeTime(meta.last_photo_at)}` : 'No photos yet'}
                        </span>
                        <span className="font-mono text-xs text-amber-400/0 transition duration-200 group-hover:text-amber-400/90">
                            OPEN DARKROOM →
                        </span>
                    </div>
                </div>

                {/* bottom sprocket strip */}
                <div className="border-t border-zinc-800 bg-zinc-950/40">
                    <SprocketStrip animate />
                </div>
            </Link>

            {canManage && (
                <>
                    {inviteFeedback && (
                        <p
                            role="status"
                            aria-live="polite"
                            data-testid={`invite-agent-feedback-${p.id}`}
                            className="mx-5 mt-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm font-medium text-emerald-300"
                        >
                            {inviteFeedback}
                        </p>
                    )}
                    <Modal show={showRename} onClose={closeRename} maxWidth="lg">
                <div className="bg-zinc-900 p-6 text-zinc-100 sm:p-7">
                    <div className="mb-6">
                        <p className="font-mono text-xs uppercase tracking-[0.2em] text-amber-400/90">
                            PROJECT NOTES
                        </p>
                        <h2 id={`rename-project-title-${p.id}`} className="mt-2 text-xl font-semibold text-zinc-100">
                            Rename project
                        </h2>
                        <p className="mt-2 text-sm leading-relaxed text-zinc-400">
                            Keep the project name and working notes current for this darkroom.
                        </p>
                    </div>

                    <form
                        onSubmit={submitRename}
                        aria-labelledby={`rename-project-title-${p.id}`}
                        data-testid={`rename-project-form-${p.id}`}
                        className="space-y-5"
                    >
                        <div>
                            <label htmlFor={`rename-project-name-${p.id}`} className="block text-sm font-medium text-zinc-200">
                                Project name <span className="text-amber-400" aria-hidden="true">*</span>
                            </label>
                            <input
                                ref={nameInput}
                                id={`rename-project-name-${p.id}`}
                                name="name"
                                type="text"
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                                required
                                disabled={processing}
                                maxLength={255}
                                autoComplete="off"
                                aria-invalid={Boolean(errors.name)}
                                aria-describedby={errors.name ? `rename-project-name-error-${p.id}` : undefined}
                                className="mt-2 block w-full rounded-md border-zinc-700 bg-zinc-950 text-sm text-zinc-100 shadow-sm focus:border-amber-400 focus:ring-amber-400"
                            />
                            {errors.name && (
                                <p id={`rename-project-name-error-${p.id}`} role="alert" className="mt-2 text-sm text-red-300">
                                    {errors.name}
                                </p>
                            )}
                        </div>

                        <div>
                            <label htmlFor={`rename-project-description-${p.id}`} className="block text-sm font-medium text-zinc-200">
                                Description <span className="text-zinc-500">(optional)</span>
                            </label>
                            <textarea
                                ref={descriptionInput}
                                id={`rename-project-description-${p.id}`}
                                name="description"
                                value={data.description}
                                onChange={(event) => setData('description', event.target.value)}
                                disabled={processing}
                                maxLength={5000}
                                rows={4}
                                aria-invalid={Boolean(errors.description)}
                                aria-describedby={errors.description ? `rename-project-description-error-${p.id}` : undefined}
                                className="mt-2 block w-full rounded-md border-zinc-700 bg-zinc-950 text-sm text-zinc-100 shadow-sm focus:border-amber-400 focus:ring-amber-400"
                            />
                            {errors.description && (
                                <p id={`rename-project-description-error-${p.id}`} role="alert" className="mt-2 text-sm text-red-300">
                                    {errors.description}
                                </p>
                            )}
                        </div>

                        <div className="flex flex-wrap justify-end gap-3 border-t border-zinc-800 pt-5">
                            <button
                                type="button"
                                data-testid={`rename-project-cancel-${p.id}`}
                                onClick={closeRename}
                                disabled={processing}
                                className="td-press rounded-lg border border-zinc-700 px-4 py-2 text-sm font-semibold text-zinc-300 transition hover:border-zinc-500 hover:text-zinc-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                data-testid={`rename-project-submit-${p.id}`}
                                disabled={processing}
                                className="td-press inline-flex items-center gap-2 rounded-lg border border-amber-400/60 bg-amber-400 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-300 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {processing ? (<><span className="td-spinner" aria-hidden="true" /> Saving…</>) : 'Save project'}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>

            <Modal show={showDelete} onClose={() => !deleting && setShowDelete(false)} maxWidth="md">
                <div className="bg-zinc-900 p-6 text-zinc-100 sm:p-7">
                    <p className="font-mono text-xs uppercase tracking-[0.2em] text-rose-600">IRREVERSIBLE CUT</p>
                    <h2 id={`delete-project-title-${p.id}`} className="mt-2 text-xl font-semibold text-zinc-100">
                        Delete project?
                    </h2>
                    <p className="mt-3 text-sm leading-relaxed text-zinc-400">
                        This permanently removes <span className="text-zinc-200">{p.name}</span>, including its photos and proposals.
                    </p>
                    <p className="mt-3 text-xs leading-relaxed text-rose-600/80">
                        The project and all of its darkroom history cannot be recovered.
                    </p>
                    <div className="mt-6 flex flex-wrap justify-end gap-3 border-t border-zinc-800 pt-5">
                        <button
                            type="button"
                            data-testid={`delete-project-cancel-${p.id}`}
                            onClick={() => setShowDelete(false)}
                            disabled={deleting}
                            className="td-press rounded-lg border border-zinc-700 px-4 py-2 text-sm font-semibold text-zinc-300 transition hover:border-zinc-500 hover:text-zinc-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Keep project
                        </button>
                        <DangerButton
                            type="button"
                            data-testid={`delete-project-confirm-${p.id}`}
                            onClick={confirmDelete}
                            disabled={deleting}
                        >
                            {deleting ? (<><span className="td-spinner" aria-hidden="true" /> Deleting…</>) : 'Delete project'}
                        </DangerButton>
                    </div>
                </div>
                    </Modal>

                    <Modal show={showInviteAgent} onClose={closeInviteAgent} maxWidth="md">
                        <div
                            data-testid={`invite-agent-dialog-${p.id}`}
                            className="bg-zinc-900 p-6 text-zinc-100 sm:p-7"
                        >
                            <div className="mb-6">
                                <p className="font-mono text-xs uppercase tracking-[0.2em] text-amber-400/90">
                                    PROJECT ACCESS
                                </p>
                                <h2 id={`invite-agent-title-${p.id}`} className="mt-2 text-xl font-semibold text-zinc-100">
                                    Invite an agent
                                </h2>
                                <p id={`invite-agent-description-${p.id}`} className="mt-2 text-sm leading-relaxed text-zinc-400">
                                    Add an existing agent account to {p.name}. Agents can propose, never decide.
                                </p>
                            </div>

                            <form
                                onSubmit={submitInviteAgent}
                                aria-labelledby={`invite-agent-title-${p.id}`}
                                aria-describedby={`invite-agent-description-${p.id}`}
                                data-testid={`invite-agent-form-${p.id}`}
                                className="space-y-5"
                            >
                                <div>
                                    <label htmlFor={`invite-agent-email-${p.id}`} className="block text-sm font-medium text-zinc-200">
                                        Agent email <span className="text-amber-400" aria-hidden="true">*</span>
                                    </label>
                                    <input
                                        ref={inviteEmailInput}
                                        id={`invite-agent-email-${p.id}`}
                                        name="email"
                                        type="email"
                                        value={inviteData.email}
                                        onChange={(event) => setInviteData('email', event.target.value)}
                                        required
                                        disabled={inviteProcessing}
                                        maxLength={255}
                                        autoComplete="email"
                                        aria-invalid={Boolean(inviteErrors.email)}
                                        aria-describedby={inviteErrors.email ? `invite-agent-email-error-${p.id}` : undefined}
                                        className="mt-2 block w-full rounded-md border-zinc-700 bg-zinc-950 text-sm text-zinc-100 shadow-sm focus:border-amber-400 focus:ring-amber-400"
                                    />
                                    {inviteErrors.email && (
                                        <p id={`invite-agent-email-error-${p.id}`} role="alert" className="mt-2 text-sm text-red-300">
                                            {inviteErrors.email}
                                        </p>
                                    )}
                                    <p className="mt-2 text-xs leading-relaxed text-zinc-500">
                                        Only existing agent accounts can be invited.
                                    </p>
                                </div>

                                <div className="flex flex-wrap justify-end gap-3 border-t border-zinc-800 pt-5">
                                    <button
                                        type="button"
                                        data-testid={`invite-agent-cancel-${p.id}`}
                                        onClick={closeInviteAgent}
                                        disabled={inviteProcessing}
                                        className="td-press rounded-lg border border-zinc-700 px-4 py-2 text-sm font-semibold text-zinc-300 transition hover:border-zinc-500 hover:text-zinc-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        data-testid={`invite-agent-submit-${p.id}`}
                                        disabled={inviteProcessing}
                                        className="td-press inline-flex items-center gap-2 rounded-lg border border-amber-400/60 bg-amber-400 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-300 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {inviteProcessing ? (<><span className="td-spinner" aria-hidden="true" /> Inviting…</>) : 'Invite agent'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </Modal>
                </>
            )}
        </div>
    );
}

type FocusableRef = {
    readonly current: { focus: () => void } | null;
};

export function focusFirstProjectField(
    errors: { name?: unknown; description?: unknown },
    nameInput: FocusableRef,
    descriptionInput: FocusableRef,
): void {
    if (errors.name !== undefined) {
        nameInput.current?.focus();
        return;
    }

    if (errors.description !== undefined) {
        descriptionInput.current?.focus();
    }
}

function CreateProjectDialog() {
    const [show, setShow] = useState(false);
    const nameInput = useRef<HTMLInputElement>(null);
    const descriptionInput = useRef<HTMLTextAreaElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const { data, setData, post, errors, processing, reset, clearErrors } = useForm({
        name: '',
        description: '',
    });

    useEffect(() => {
        if (show) {
            nameInput.current?.focus();
        }
    }, [show]);

    const open = () => {
        clearErrors();
        setShow(true);
    };

    const close = () => {
        if (processing) {
            return;
        }

        reset();
        clearErrors();
        setShow(false);
        trigger.current?.focus();
    };

    const submit: FormEventHandler<HTMLFormElement> = (event) => {
        event.preventDefault();
        post(route('projects.store'), {
            preserveScroll: true,
            onError: (validationErrors) => {
                focusFirstProjectField(validationErrors, nameInput, descriptionInput);
            },
        });
    };

    return (
        <>
            <button
                ref={trigger}
                type="button"
                data-testid="dashboard-add-project"
                aria-haspopup="dialog"
                aria-expanded={show}
                onClick={open}
                className="td-press inline-flex items-center gap-1.5 rounded-lg border border-amber-400/60 bg-amber-400 px-3 py-2 text-sm font-semibold text-white transition hover:bg-amber-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-300 focus-visible:ring-offset-2 focus-visible:ring-offset-zinc-950"
            >
                <span aria-hidden="true" className="text-base leading-none">+</span>
                New project
            </button>

            <Modal show={show} onClose={close} maxWidth="lg">
                <div className="bg-zinc-900 p-6 text-zinc-100 sm:p-7">
                    <div className="mb-6">
                        <p className="font-mono text-xs uppercase tracking-[0.2em] text-amber-400/90">
                            NEW DARKROOM
                        </p>
                        <h2 id="new-project-title" className="mt-2 text-xl font-semibold text-zinc-100">
                            Create a project
                        </h2>
                        <p id="new-project-description" className="mt-2 text-sm leading-relaxed text-zinc-400">
                            Start a workspace for a new shoot. You can invite an agent after the project is ready.
                        </p>
                    </div>

                    <form
                        onSubmit={submit}
                        aria-labelledby="new-project-title"
                        data-testid="new-project-form"
                        className="space-y-5"
                    >
                        <div>
                            <label htmlFor="project-name" className="block text-sm font-medium text-zinc-200">
                                Project name <span className="text-amber-400" aria-hidden="true">*</span>
                            </label>
                            <input
                                ref={nameInput}
                                id="project-name"
                                name="name"
                                type="text"
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                                required
                                disabled={processing}
                                maxLength={255}
                                autoComplete="off"
                                aria-invalid={Boolean(errors.name)}
                                aria-describedby={errors.name ? 'project-name-error' : undefined}
                                className="mt-2 block w-full rounded-md border-zinc-700 bg-zinc-950 text-sm text-zinc-100 shadow-sm focus:border-amber-400 focus:ring-amber-400"
                            />
                            {errors.name && (
                                <p id="project-name-error" role="alert" className="mt-2 text-sm text-red-300">
                                    {errors.name}
                                </p>
                            )}
                        </div>

                        <div>
                            <label htmlFor="project-description" className="block text-sm font-medium text-zinc-200">
                                Description <span className="text-zinc-500">(optional)</span>
                            </label>
                            <textarea
                                ref={descriptionInput}
                                id="project-description"
                                name="description"
                                value={data.description}
                                onChange={(event) => setData('description', event.target.value)}
                                disabled={processing}
                                maxLength={5000}
                                rows={4}
                                aria-invalid={Boolean(errors.description)}
                                aria-describedby={errors.description ? 'project-description-error' : undefined}
                                className="mt-2 block w-full rounded-md border-zinc-700 bg-zinc-950 text-sm text-zinc-100 shadow-sm focus:border-amber-400 focus:ring-amber-400"
                            />
                            {errors.description && (
                                <p id="project-description-error" role="alert" className="mt-2 text-sm text-red-300">
                                    {errors.description}
                                </p>
                            )}
                        </div>

                        <div className="flex flex-wrap justify-end gap-3 border-t border-zinc-800 pt-5">
                            <button
                                type="button"
                                data-testid="new-project-cancel"
                                onClick={close}
                                disabled={processing}
                                className="td-press rounded-lg border border-zinc-700 px-4 py-2 text-sm font-semibold text-zinc-300 transition hover:border-zinc-500 hover:text-zinc-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                data-testid="new-project-submit"
                                disabled={processing}
                                className="td-press inline-flex items-center gap-2 rounded-lg border border-amber-400/60 bg-amber-400 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-300 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {processing ? (<><span className="td-spinner" aria-hidden="true" /> Creating…</>) : 'Create project'}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>
        </>
    );
}

export default function Dashboard({ projects, can_create_project, project_meta, tools, agent, now }: DashboardProps) {
    const hasProjects = projects.length > 0;
    const canCreateProject = can_create_project === true;

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-zinc-100">Dashboard</h1>
                        <p className="mt-1 text-sm text-zinc-400">
                            Your darkroom: the agent develops, you keep the final cut.
                        </p>
                    </div>
                    {/* Right cluster: status stamp sits left of the primary CTA so the button hugs the page edge */}
                    <div className="flex flex-wrap items-center gap-4">
                        <p className="font-mono text-xs uppercase tracking-[0.2em] text-zinc-600" suppressHydrationWarning>
                            {now ? `SAFELIGHT ON · ${new Date(now).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}` : 'SAFELIGHT ON'}
                        </p>
                        {canCreateProject && <CreateProjectDialog />}
                    </div>
                </div>
            }
        >
            <Head title="Dashboard" />

            <div className="py-10">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    {hasProjects ? (
                        <div className="grid gap-6 lg:grid-cols-3">
                            {/* Project film strips — 2/3 width, primary surface */}
                            <div className="lg:col-span-2">
                                <h2 className="td-fade-in mb-3 text-sm font-semibold text-zinc-200">Projects</h2>
                                <div className="grid gap-5 md:grid-cols-2">
                                    {projects.map((p) => (
                                        <FilmProjectCard
                                            key={p.id}
                                            p={p}
                                            meta={project_meta?.[p.id]}
                                            canManage={project_meta?.[p.id]?.can_manage === true}
                                        />
                                    ))}
                                </div>
                            </div>

                            {/* Authority story — the reason this app exists */}
                            <div className="space-y-6">
                                {tools && <ToolLadder tools={tools} />}
                                {agent && <AgentPresencePanel agent={agent} />}
                            </div>
                        </div>
                    ) : (
                        <div className="td-fade-up mx-auto max-w-xl rounded-xl border border-zinc-800 bg-zinc-900/60 p-10 text-center">
                            <div aria-hidden="true" className="mx-auto mb-5 w-fit rounded-lg bg-zinc-950/60 px-4 py-3">
                                <SprocketStrip />
                            </div>
                            <h2 className="text-lg font-semibold text-zinc-100">No film in the darkroom yet</h2>
                            <p className="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-zinc-400">
                                Projects appear here as film strips. Create one from a photographer account,
                                then invite an agent to propose: never to decide.
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
