/**
 * Project Report — the post-execution "hand the work back" page.
 *
 * Rendered from a single server payload (ProjectReportController@payload);
 * the Markdown export is built client-side from the SAME payload so the
 * on-screen report and the downloaded artifact can never diverge.
 *
 * Human-only surface: the controller 403s agent accounts on both the page
 * and the markdown endpoint, and nothing here registers a WebMCP tool.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';

export interface ReportBrief {
    client: string | null;
    shoot_date: string | null;
    location: string | null;
    creative_direction: string | null;
    tonality_notes: string | null;
    deliverables: string | null;
    status: string;
}

export interface ReportProposalItem {
    filename: string | null;
    action: string | null;
    rationale: string | null;
    status: string;
}

export interface ReportProposal {
    id: number;
    type: string;
    status: string;
    summary: string | null;
    created_by: string | null;
    reviewed_at: string | null;
    executed_at: string | null;
    items: ReportProposalItem[];
}

export interface ReportDecision {
    id: number;
    proposal_id: number | null;
    photographer: string | null;
    decision: string;
    note: string | null;
    decided_at: string;
}

export interface ReportFinding {
    id: number;
    severity: string;
    category: string;
    message: string;
    status: string;
}

export interface ReportDerivative {
    id: number;
    type: string;
    filename: string | null;
    source_url: string | null;
    url: string | null;
    adjustments: Record<string, unknown>;
    provenance: string | null;
    reverted_at: string | null;
    created_at: string | null;
}

export interface ReportPayload {
    project: { id: number; name: string; description: string | null };
    generated_at: string;
    brief: ReportBrief | null;
    selection: { total: number; selected: number; culled: number; unreviewed: number };
    counts: {
        proposals: number;
        proposals_executed: number;
        decisions: number;
        findings_open: number;
        derivatives_active: number;
        derivatives_reverted: number;
    };
    proposals: ReportProposal[];
    decisions: ReportDecision[];
    findings: ReportFinding[];
    derivatives: ReportDerivative[];
}

/** Client-side twin of ProjectReportController::toMarkdown(). */
export function buildReportMarkdown(r: ReportPayload): string {
    const lines: string[] = [];
    lines.push(`# Session Report — ${r.project.name}`);
    lines.push('');
    lines.push(`Generated: ${r.generated_at}`);
    if (r.project.description) {
        lines.push('');
        lines.push(r.project.description);
    }

    lines.push('');
    lines.push('## Selection summary');
    lines.push('');
    lines.push(
        `- Photos: ${r.selection.total} (${r.selection.selected} selected, ${r.selection.culled} culled, ${r.selection.unreviewed} unreviewed)`,
    );
    lines.push(`- Proposals: ${r.counts.proposals} (${r.counts.proposals_executed} executed)`);
    lines.push(`- Photographer decisions: ${r.counts.decisions}`);
    lines.push(`- Open QA findings: ${r.counts.findings_open}`);
    lines.push(`- Deliverables: ${r.counts.derivatives_active} active (${r.counts.derivatives_reverted} reverted)`);

    if (r.brief) {
        lines.push('');
        lines.push(`## Creative brief (${r.brief.status})`);
        lines.push('');
        if (r.brief.client) lines.push(`- Client: ${r.brief.client}`);
        if (r.brief.shoot_date) lines.push(`- Shoot date: ${r.brief.shoot_date}`);
        if (r.brief.location) lines.push(`- Location: ${r.brief.location}`);
        if (r.brief.creative_direction) lines.push('', `**Creative direction**: ${r.brief.creative_direction}`);
        if (r.brief.tonality_notes) lines.push('', `**Tonality**: ${r.brief.tonality_notes}`);
        if (r.brief.deliverables) lines.push('', `**Deliverables**: ${r.brief.deliverables}`);
    }

    lines.push('');
    lines.push('## Agent proposals');
    lines.push('');
    if (r.proposals.length === 0) lines.push('_No proposals were made in this session._');
    for (const p of r.proposals) {
        lines.push(`- **#${p.id} ${p.type}** — ${p.summary ?? ''} (${p.status})`);
        for (const i of p.items) {
            lines.push(`  - ${i.filename ?? 'unknown'}: ${i.action ?? 'n/a'} — ${i.rationale ?? ''}`);
        }
    }

    lines.push('');
    lines.push('## Photographer decisions');
    lines.push('');
    if (r.decisions.length === 0) lines.push('_No decisions recorded._');
    for (const d of r.decisions) {
        const note = d.note ? `; note: ${d.note}` : '';
        lines.push(`- ${d.photographer ?? 'Unknown'} → **${d.decision}** (proposal #${d.proposal_id ?? 0}${note})`);
    }

    lines.push('');
    lines.push('## QA findings');
    lines.push('');
    if (r.findings.length === 0) lines.push('_No QA findings._');
    for (const f of r.findings) {
        lines.push(`- [${f.severity}/${f.category}] ${f.message} (${f.status})`);
    }

    lines.push('');
    lines.push('## Deliverables (agent-executed derivatives)');
    lines.push('');
    if (r.derivatives.length === 0) lines.push('_No derivatives were executed in this session._');
    for (const d of r.derivatives) {
        const reverted = d.reverted_at ? ' [REVERTED]' : '';
        lines.push(`- ${d.filename ?? 'unknown'} — ${d.type}${reverted}`);
        lines.push(`  - source: ${d.source_url ?? 'n/a'}`);
        lines.push(`  - rendered: ${d.url ?? 'n/a'}`);
        const pairs = Object.entries(d.adjustments ?? {}).map(
            ([k, v]) => `${k}=${typeof v === 'boolean' ? (v ? 'true' : 'false') : String(v)}`,
        );
        if (pairs.length > 0) lines.push(`  - adjustments: ${pairs.join(', ')}`);
        lines.push(`  - provenance: ${d.provenance ?? 'n/a'}`);
    }

    lines.push('');
    lines.push('---');
    lines.push('');
    lines.push('Generated by Thinking Darkroom — every value above was approved by the photographer before execution.');

    return lines.join('\n') + '\n';
}

function StatChip({ label, value, testid }: { label: string; value: number | string; testid?: string }) {
    return (
        <div
            data-testid={testid}
            className="rounded-lg border border-zinc-800 bg-zinc-900/60 px-4 py-3"
        >
            <div className="text-2xl font-semibold text-zinc-100">{value}</div>
            <div className="mt-0.5 text-xs uppercase tracking-wide text-zinc-500">{label}</div>
        </div>
    );
}

function SectionTitle({ children }: { children: React.ReactNode }) {
    return <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-400">{children}</h2>;
}

function EmptyNote({ children }: { children: React.ReactNode }) {
    return <p className="text-sm text-zinc-500">{children}</p>;
}

function statusBadgeClass(status: string): string {
    if (status === 'executed' || status === 'resolved') return 'border-emerald-500/40 text-emerald-400';
    if (status === 'rejected' || status === 'critical' || status === 'error') return 'border-red-500/40 text-red-400';
    if (status === 'approved' || status === 'acknowledged') return 'border-sky-500/40 text-sky-400';
    if (status === 'warning') return 'border-amber-500/40 text-amber-400';
    return 'border-zinc-700 text-zinc-400';
}

function StatusBadge({ status }: { status: string }) {
    return (
        <span className={`rounded border px-1.5 py-0.5 text-[11px] font-medium ${statusBadgeClass(status)}`}>
            {status}
        </span>
    );
}

interface PageProps extends Record<string, unknown> {
    auth: { user: { id: number; name: string; email: string } };
    report: ReportPayload;
}

export default function ProjectReport() {
    const { report } = usePage<PageProps>().props;
    const projectId = report.project.id;

    const exportMarkdown = async () => {
        const markdown = buildReportMarkdown(report);
        const blob = new Blob([markdown], { type: 'text/markdown;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = `report-${report.project.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${projectId}.md`;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(url);
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-base font-semibold text-zinc-100">
                            Session report — {report.project.name}
                        </h2>
                        <p className="text-xs text-zinc-500">
                            Generated {report.generated_at}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <a
                            href={route('projects.report.deliverables-zip', projectId)}
                            data-testid="report-download-zip"
                            className="rounded-md border border-zinc-700 px-3 py-1.5 text-xs font-semibold text-zinc-200 hover:bg-zinc-800"
                        >
                            Export package (.zip)
                        </a>
                        <button
                            type="button"
                            data-testid="report-export-md"
                            onClick={exportMarkdown}
                            className="rounded-md border border-zinc-700 px-3 py-1.5 text-xs font-semibold text-zinc-200 hover:bg-zinc-800"
                        >
                            Export report (.md)
                        </button>
                        <Link
                            href={route('workspace.show', projectId)}
                            className="rounded-md border border-zinc-700 px-3 py-1.5 text-xs font-semibold text-zinc-300 hover:bg-zinc-800"
                        >
                            ← Workspace
                        </Link>
                    </div>
                </div>
            }
        >
            <Head title={`Report — ${report.project.name}`} />

            <div className="space-y-8 py-6 mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                {report.project.description ? (
                    <p data-testid="report-description" className="text-sm text-zinc-400">
                        {report.project.description}
                    </p>
                ) : null}

                {/* Selection summary */}
                <section data-testid="report-selection-summary">
                    <SectionTitle>Selection summary</SectionTitle>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        <StatChip label="Photos" value={report.selection.total} />
                        <StatChip label="Selected" value={report.selection.selected} />
                        <StatChip label="Culled" value={report.selection.culled} />
                        <StatChip label="Proposals" value={report.counts.proposals} />
                        <StatChip label="Decisions" value={report.counts.decisions} />
                        <StatChip
                            label="Deliverables"
                            value={`${report.counts.derivatives_active} active`}
                        />
                    </div>
                    {report.counts.findings_open > 0 ? (
                        <p data-testid="report-open-findings" className="mt-3 text-sm text-amber-400">
                            {report.counts.findings_open} open QA finding
                            {report.counts.findings_open === 1 ? '' : 's'} — see below.
                        </p>
                    ) : null}
                </section>

                {/* Creative brief */}
                {report.brief ? (
                    <section data-testid="report-brief">
                        <SectionTitle>Creative brief ({report.brief.status})</SectionTitle>
                        <dl className="space-y-1 rounded-lg border border-zinc-800 bg-zinc-900/60 p-4 text-sm text-zinc-300">
                            {report.brief.client ? (
                                <div><span className="text-zinc-500">Client:</span> {report.brief.client}</div>
                            ) : null}
                            {report.brief.shoot_date ? (
                                <div><span className="text-zinc-500">Shoot date:</span> {report.brief.shoot_date}</div>
                            ) : null}
                            {report.brief.location ? (
                                <div><span className="text-zinc-500">Location:</span> {report.brief.location}</div>
                            ) : null}
                            {report.brief.creative_direction ? (
                                <div><span className="text-zinc-500">Direction:</span> {report.brief.creative_direction}</div>
                            ) : null}
                            {report.brief.tonality_notes ? (
                                <div><span className="text-zinc-500">Tonality:</span> {report.brief.tonality_notes}</div>
                            ) : null}
                            {report.brief.deliverables ? (
                                <div><span className="text-zinc-500">Deliverables:</span> {report.brief.deliverables}</div>
                            ) : null}
                        </dl>
                    </section>
                ) : null}

                {/* Agent proposals */}
                <section data-testid="report-proposals">
                    <SectionTitle>Agent proposals ({report.counts.proposals})</SectionTitle>
                    {report.proposals.length === 0 ? (
                        <EmptyNote>No proposals were made in this session.</EmptyNote>
                    ) : (
                        <ul className="space-y-3">
                            {report.proposals.map((p) => (
                                <li
                                    key={p.id}
                                    data-testid={`report-proposal-${p.id}`}
                                    className="rounded-lg border border-zinc-800 bg-zinc-900/60 p-4"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-sm font-semibold text-zinc-100">
                                            #{p.id} {p.type}
                                        </span>
                                        <StatusBadge status={p.status} />
                                        {p.executed_at ? (
                                            <span className="text-xs text-emerald-400">executed</span>
                                        ) : null}
                                        {p.created_by ? (
                                            <span className="text-xs text-zinc-500">by {p.created_by}</span>
                                        ) : null}
                                    </div>
                                    {p.summary ? (
                                        <p className="mt-1.5 text-sm text-zinc-300">{p.summary}</p>
                                    ) : null}
                                    {p.items.length > 0 ? (
                                        <ul className="mt-2 space-y-1 text-xs text-zinc-400">
                                            {p.items.map((i, idx) => (
                                                <li key={idx}>
                                                    <span className="text-zinc-300">{i.filename ?? 'unknown'}</span>
                                                    {' — '}{i.action ?? 'n/a'}
                                                    {i.rationale ? ` — ${i.rationale}` : ''}
                                                </li>
                                            ))}
                                        </ul>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {/* Photographer decisions */}
                <section data-testid="report-decisions">
                    <SectionTitle>Photographer decisions ({report.decisions.length})</SectionTitle>
                    {report.decisions.length === 0 ? (
                        <EmptyNote>No decisions recorded.</EmptyNote>
                    ) : (
                        <ul className="space-y-2 text-sm text-zinc-300">
                            {report.decisions.map((d) => (
                                <li key={d.id} data-testid={`report-decision-${d.id}`}>
                                    <span className="text-zinc-100">{d.photographer ?? 'Unknown'}</span>
                                    {' → '}
                                    <span className="font-semibold">{d.decision}</span>
                                    <span className="text-zinc-500"> (proposal #{d.proposal_id ?? 0})</span>
                                    {d.note ? <span className="text-zinc-400"> — {d.note}</span> : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {/* QA findings */}
                <section data-testid="report-findings">
                    <SectionTitle>QA findings</SectionTitle>
                    {report.findings.length === 0 ? (
                        <EmptyNote>No QA findings.</EmptyNote>
                    ) : (
                        <ul className="space-y-2">
                            {report.findings.map((f) => (
                                <li
                                    key={f.id}
                                    data-testid={`report-finding-${f.id}`}
                                    className="flex flex-wrap items-center gap-2 text-sm text-zinc-300"
                                >
                                    <StatusBadge status={f.severity} />
                                    <StatusBadge status={f.status} />
                                    <span>{f.message}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {/* Deliverables */}
                <section data-testid="report-deliverables">
                    <SectionTitle>
                        Deliverables ({report.counts.derivatives_active} active
                        {report.counts.derivatives_reverted > 0
                            ? `, ${report.counts.derivatives_reverted} reverted`
                            : ''})
                    </SectionTitle>
                    {report.derivatives.length === 0 ? (
                        <EmptyNote>
                            No derivatives were executed in this session — approve a proposal and let the
                            agent execute it, then download the results here.
                        </EmptyNote>
                    ) : (
                        <ul className="grid gap-4 sm:grid-cols-2">
                            {report.derivatives.map((d) => (
                                <li
                                    key={d.id}
                                    data-testid={`report-derivative-${d.id}`}
                                    className="rounded-lg border border-zinc-800 bg-zinc-900/60 p-4"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-sm font-semibold text-zinc-100">
                                            {d.filename ?? 'unknown'}
                                        </span>
                                        <StatusBadge status={d.type} />
                                        {d.reverted_at ? (
                                            <span className="rounded border border-amber-500/40 px-1.5 py-0.5 text-[11px] text-amber-400">
                                                reverted
                                            </span>
                                        ) : null}
                                    </div>
                                    <div className="mt-3 flex gap-3">
                                        {d.source_url ? (
                                            <figure className="w-1/2">
                                                {/* eslint-disable-next-line @next/next/no-img-element */}
                                                <img
                                                    src={d.source_url}
                                                    alt={`${d.filename ?? 'photo'} source`}
                                                    className="aspect-[3/2] w-full rounded border border-zinc-800 object-cover"
                                                />
                                                <figcaption className="mt-1 text-center text-[11px] text-zinc-500">
                                                    source
                                                </figcaption>
                                            </figure>
                                        ) : null}
                                        {d.url ? (
                                            <figure className="w-1/2">
                                                <a href={d.url} target="_blank" rel="noreferrer">
                                                    {/* eslint-disable-next-line @next/next/no-img-element */}
                                                    <img
                                                        src={d.url}
                                                        alt={`${d.filename ?? 'photo'} rendered`}
                                                        className="aspect-[3/2] w-full rounded border border-zinc-800 object-cover"
                                                    />
                                                </a>
                                                <figcaption className="mt-1 text-center text-[11px] text-zinc-500">
                                                    rendered
                                                </figcaption>
                                            </figure>
                                        ) : null}
                                    </div>
                                    {Object.keys(d.adjustments ?? {}).length > 0 ? (
                                        <p className="mt-2 text-xs text-zinc-400">
                                            {Object.entries(d.adjustments)
                                                .map(([k, v]) => `${k}=${String(v)}`)
                                                .join(' · ')}
                                        </p>
                                    ) : null}
                                    {d.provenance ? (
                                        <p className="mt-1 text-xs text-zinc-500">provenance: {d.provenance}</p>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <p className="text-xs text-zinc-600">
                    Every value in this report was approved by the photographer before the agent executed it.
                </p>
            </div>
        </AuthenticatedLayout>
    );
}
