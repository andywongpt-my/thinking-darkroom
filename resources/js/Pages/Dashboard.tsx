import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import type { PageProps } from '@/types';

interface DashboardProject {
    id: number;
    name: string;
    status: string;
    photo_count: number;
    pending_proposals: number;
    url: string;
}

type ProjectMeta = {
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

function SprocketStrip() {
    return (
        <div aria-hidden="true" className="flex items-center justify-between px-2 py-1.5">
            {Array.from({ length: 10 }).map((_, i) => (
                <span key={i} className="h-1.5 w-2.5 rounded-[2px] bg-black/60" />
            ))}
        </div>
    );
}

function ToolLadder({ tools }: { tools: DashboardTools }) {
    const rungs: Array<{ label: string; count: number; note: string; tone: string }> = [
        { label: 'READ', count: tools.byAuthority.READ ?? 0, note: 'observe pixels, never change them', tone: 'text-zinc-400' },
        { label: 'ANALYZE', count: tools.byAuthority.ANALYZE ?? 0, note: 'compute judgment, hold it', tone: 'text-zinc-400' },
        { label: 'PROPOSE', count: tools.byAuthority.PROPOSE ?? 0, note: 'recommend — human decides', tone: 'text-zinc-400' },
        { label: 'EXECUTE', count: tools.byAuthority.EXECUTE ?? 0, note: 'exists only after approval', tone: 'text-amber-400/90' },
    ];

    return (
        <section aria-label="Agent authority model" className="rounded-xl border border-zinc-800 bg-zinc-900/60">
            <div className="flex items-baseline justify-between border-b border-zinc-800 px-5 py-4">
                <h2 className="text-sm font-semibold text-zinc-200">Agent authority ladder</h2>
                <span className="font-mono text-xs text-zinc-500" data-testid="dashboard-tool-count">
                    {tools.total} tools
                </span>
            </div>
            <ol className="divide-y divide-zinc-800/70">
                {rungs.map((r) => (
                    <li key={r.label} className="flex items-center gap-4 px-5 py-3">
                        <span className="w-20 font-mono text-sm font-medium text-zinc-200">{r.label}</span>
                        <span className="font-mono text-sm tabular-nums text-zinc-300">{String(r.count).padStart(2, '0')}</span>
                        <span className={`text-xs ${r.tone}`}>{r.note}</span>
                    </li>
                ))}
            </ol>
            {tools.dynamic && (
                <p className="border-t border-zinc-800 px-5 py-3 text-xs leading-relaxed text-zinc-400">
                    <span className="font-mono text-amber-400/90">{tools.dynamic.name}</span>{' '}
                    is registered the moment you approve a proposal — and unregistered the instant it runs.
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
                    className={`h-2 w-2 rounded-full ${agent.online ? 'bg-amber-400' : 'bg-zinc-600'}`}
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

function FilmProjectCard({ p, meta }: { p: DashboardProject; meta?: ProjectMeta }) {
    const executed = meta?.executed_proposals ?? 0;
    const approved = meta?.approved_proposals ?? 0;

    return (
        <Link
            href={p.url}
            data-testid={`dashboard-project-${p.id}`}
            className="group relative block overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/60 transition duration-200 hover:border-amber-400/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400/60"
        >
            {/* top sprocket strip */}
            <div className="border-b border-zinc-800 bg-zinc-950/40">
                <SprocketStrip />
            </div>

            <div className="p-5">
                <div className="flex items-center gap-3">
                    <span
                        className={`h-1.5 w-1.5 shrink-0 rounded-full ${
                            p.pending_proposals > 0 ? 'animate-pulse bg-amber-400' : 'bg-zinc-600'
                        }`}
                        aria-hidden="true"
                    />
                    <h3 className="min-w-0 truncate text-base font-semibold text-zinc-100">{p.name}</h3>
                    <span className="ml-auto shrink-0 font-mono text-[10px] tracking-[0.18em] text-zinc-500">
                        {STATUS_LABEL[p.status] ?? p.status.toUpperCase()}
                    </span>
                </div>

                <div className="mt-5 grid grid-cols-3 gap-2 text-center" data-testid={`dashboard-project-${p.id}-stats`}>
                    <div className="rounded-lg border border-zinc-800/80 bg-zinc-950/40 py-2.5">
                        <dd className="font-mono text-lg font-semibold tabular-nums text-zinc-100">{p.photo_count}</dd>
                        <dt className="mt-0.5 text-[11px] text-zinc-500">photos</dt>
                    </div>
                    <div className="rounded-lg border border-zinc-800/80 bg-zinc-950/40 py-2.5">
                        <dd
                            className={`font-mono text-lg font-semibold tabular-nums ${
                                p.pending_proposals > 0 ? 'text-amber-400' : 'text-zinc-100'
                            }`}
                        >
                            {p.pending_proposals}
                        </dd>
                        <dt className="mt-0.5 text-[11px] text-zinc-500">awaiting you</dt>
                    </div>
                    <div className="rounded-lg border border-zinc-800/80 bg-zinc-950/40 py-2.5">
                        <dd className="font-mono text-lg font-semibold tabular-nums text-zinc-100">{executed}</dd>
                        <dt className="mt-0.5 text-[11px] text-zinc-500">executed</dt>
                    </div>
                </div>

                {(approved > 0 || executed > 0) && (
                    <p className="mt-3 font-mono text-[11px] text-zinc-600">
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
                <SprocketStrip />
            </div>
        </Link>
    );
}

export default function Dashboard({ projects, project_meta, tools, agent, now }: DashboardProps) {
    const hasProjects = projects.length > 0;

    return (
        <AuthenticatedLayout
            dark
            header={
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-zinc-100">Dashboard</h1>
                        <p className="mt-1 text-sm text-zinc-400">
                            Your darkroom — the agent develops, you keep the final cut.
                        </p>
                    </div>
                    <p className="font-mono text-[10px] uppercase tracking-[0.2em] text-zinc-600" suppressHydrationWarning>
                        {now ? `SAFELIGHT ON · ${new Date(now).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}` : 'SAFELIGHT ON'}
                    </p>
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
                                <h2 className="mb-3 text-sm font-semibold text-zinc-200">Projects</h2>
                                <div className="grid gap-5 md:grid-cols-2">
                                    {projects.map((p) => (
                                        <FilmProjectCard key={p.id} p={p} meta={project_meta?.[p.id]} />
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
                        <div className="mx-auto max-w-xl rounded-xl border border-zinc-800 bg-zinc-900/60 p-10 text-center">
                            <div aria-hidden="true" className="mx-auto mb-5 w-fit rounded-lg bg-zinc-950/60 px-4 py-3">
                                <SprocketStrip />
                            </div>
                            <h2 className="text-lg font-semibold text-zinc-100">No film in the darkroom yet</h2>
                            <p className="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-zinc-400">
                                Projects appear here as film strips. Create one from a photographer account,
                                then invite an agent to propose — never to decide.
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
