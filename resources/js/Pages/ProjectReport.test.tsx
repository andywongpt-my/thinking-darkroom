/**
 * Project Report page — render + Markdown export tests.
 *
 * Same pattern as the culling/creative-room suites: the Workspace page is
 * rendered with `react-dom/server`, Inertia and route() are mocked at the
 * module boundary, everything under them runs as real code. buildReportMarkdown
 * is exercised directly as the client-side twin of the server payload.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createElement, Fragment } from 'react';
import { renderToString } from 'react-dom/server';
import { resetWebmcpDetection } from '@/webmcp/model-context';
import type { ReportPayload } from '@/Pages/ProjectReport';

/* --------------------------------------------------------------- inertia mock */

let pageFixture: Record<string, unknown> & { props: Record<string, unknown> };

vi.mock('@inertiajs/react', () => {
    const Head = ({ children }: { children?: React.ReactNode }) =>
        createElement(Fragment, null, children);
    const Link = (props: Record<string, unknown>) =>
        createElement('a', { href: String(props.href ?? '#') }, props.children as React.ReactNode);
    return {
        Head,
        Link,
        router: { reload: vi.fn(), visit: vi.fn(), post: vi.fn() },
        usePage: () => pageFixture,
    };
});

vi.mock('@/Layouts/AuthenticatedLayout', () => ({
    default: ({ header, children }: { header?: React.ReactNode; children: React.ReactNode }) =>
        createElement(
            'div',
            { 'data-testid': 'authenticated-shell' },
            createElement('header', null, header),
            createElement('main', null, children),
        ),
}));

(globalThis as Record<string, unknown>).route = (name: string, id?: number) =>
    name === 'projects.report.show' ? `/projects/${id}/report` : `/${name}`;

/* -------------------------------------------------------------- fixture */

function makeReport(): ReportPayload {
    return {
        project: {
            id: 7,
            name: 'Golden Hour',
            description: 'Waterfront engagement set.',
        },
        generated_at: '2026-09-04T05:30:00.000Z',
        brief: {
            client: 'Nadia & Ravi',
            shoot_date: '2026-08-30',
            location: 'Tanjung Aru waterfront',
            creative_direction: 'Warm dusk tones, candid motion',
            tonality_notes: 'Soft highlights, deep shadows',
            deliverables: '30 retouched frames, web + print',
            status: 'active',
        },
        selection: { total: 12, selected: 8, culled: 3, unreviewed: 1 },
        counts: {
            proposals: 2,
            proposals_executed: 1,
            decisions: 3,
            findings_open: 1,
            derivatives_active: 1,
            derivatives_reverted: 1,
        },
        proposals: [
            {
                id: 1,
                type: 'retouch',
                status: 'executed',
                summary: 'Lift exposure on hero frames',
                created_by: 'Darkroom Agent',
                reviewed_at: '2026-09-04T05:00:00.000Z',
                executed_at: '2026-09-04T05:10:00.000Z',
                items: [
                    {
                        filename: 'IMG_0001.jpg',
                        action: 'adjust',
                        rationale: 'Underexposed by ~1EV',
                        status: 'executed',
                    },
                ],
            },
            {
                id: 2,
                type: 'cull',
                status: 'rejected',
                summary: 'Cull blurred burst frames',
                created_by: 'Darkroom Agent',
                reviewed_at: '2026-09-04T04:40:00.000Z',
                executed_at: null,
                items: [],
            },
        ],
        decisions: [
            {
                id: 11,
                proposal_id: 1,
                photographer: 'Maya Photographer',
                decision: 'approve',
                note: 'Brighter, keep contrast',
                decided_at: '2026-09-04T04:58:00.000Z',
            },
            {
                id: 12,
                proposal_id: 2,
                photographer: 'Maya Photographer',
                decision: 'reject',
                note: null,
                decided_at: '2026-09-04T04:45:00.000Z',
            },
        ],
        findings: [
            {
                id: 21,
                severity: 'warning',
                category: 'exposure',
                message: 'Two frames still read flat',
                status: 'open',
            },
        ],
        derivatives: [
            {
                id: 31,
                type: 'retouched',
                filename: 'IMG_0001.jpg',
                source_url: 'https://blob.example/src.jpg',
                url: 'https://blob.example/retouched.jpg',
                adjustments: { exposure: 10, contrast: 5 },
                provenance: 'lut6key:v1',
                reverted_at: null,
                created_at: '2026-09-04T05:10:00.000Z',
            },
            {
                id: 32,
                type: 'retouched',
                filename: 'IMG_0001.jpg',
                source_url: 'https://blob.example/src.jpg',
                url: 'https://blob.example/retouched-v0.jpg',
                adjustments: { exposure: 20 },
                provenance: 'lut6key:v1',
                reverted_at: '2026-09-04T05:20:00.000Z',
                created_at: '2026-09-04T05:05:00.000Z',
            },
        ],
    };
}

/* ------------------------------------------------------------------- tests */

const reportModule = await import('@/Pages/ProjectReport');
const ProjectReportPage = reportModule.default as React.FC;

describe('ProjectReport page', () => {
    beforeEach(() => {
        resetWebmcpDetection();
        pageFixture = {
            props: {
                auth: { user: { id: 1, name: 'Maya Photographer', email: 'photographer@webmcp.test' } },
                report: makeReport(),
            },
        };
        (globalThis as Record<string, unknown>).document = {
            modelContext: undefined,
        };
    });

    afterEach(() => {
        delete (globalThis as Record<string, unknown>).document;
    });

    it('renders the full session story: stats, proposals, decisions, findings, deliverables', () => {
        const html = renderToString(createElement(ProjectReportPage));

        expect(html).toContain('Session report');
        expect(html).toContain('Golden Hour');
        expect(html).toContain('data-testid="report-selection-summary"');
        expect(html).toContain('Photos');
        expect(html).toContain('data-testid="report-brief"');
        expect(html).toContain('Nadia &amp; Ravi');
        expect(html).toContain('data-testid="report-proposal-1"');
        expect(html).toContain('Lift exposure on hero frames');
        expect(html).toContain('data-testid="report-proposal-2"');
        expect(html).toContain('rejected');
        expect(html).toContain('data-testid="report-decision-11"');
        expect(html).toContain('approve');
        expect(html).toContain('Brighter, keep contrast');
        expect(html).toContain('data-testid="report-finding-21"');
        expect(html).toContain('Two frames still read flat');
        expect(html).toContain('data-testid="report-derivative-31"');
        expect(html).toContain('provenance:');
        expect(html).toContain('lut6key:v1');
        // reverted derivative is labeled
        expect(html).toContain('reverted');
        // source and rendered images both render
        expect(html).toContain('src="https://blob.example/src.jpg"');
        expect(html).toContain('src="https://blob.example/retouched.jpg"');
        // export affordance + back link
        expect(html).toContain('data-testid="report-export-md"');
        expect(html).toContain('/projects/7/report');
    });

    it('renders honest empty states when the session has no work yet', () => {
        const empty = makeReport();
        empty.proposals = [];
        empty.decisions = [];
        empty.findings = [];
        empty.derivatives = [];
        empty.counts = {
            proposals: 0,
            proposals_executed: 0,
            decisions: 0,
            findings_open: 0,
            derivatives_active: 0,
            derivatives_reverted: 0,
        };
        empty.brief = null;
        pageFixture = {
            props: {
                auth: { user: { id: 1, name: 'Maya Photographer', email: 'photographer@webmcp.test' } },
                report: empty,
            },
        };

        const html = renderToString(createElement(ProjectReportPage));

        expect(html).not.toContain('data-testid="report-brief"');
        expect(html).toContain('No proposals were made in this session.');
        expect(html).toContain('No decisions recorded.');
        expect(html).toContain('No QA findings.');
        expect(html).toContain('No derivatives were executed in this session');
    });

    it('buildReportMarkdown mirrors the server payload as a portable artifact', () => {
        const markdown = reportModule.buildReportMarkdown(makeReport());

        expect(markdown).toContain('# Session Report — Golden Hour');
        expect(markdown).toContain('Generated: 2026-09-04T05:30:00.000Z');
        expect(markdown).toContain('- Photos: 12 (8 selected, 3 culled, 1 unreviewed)');
        expect(markdown).toContain('- Proposals: 2 (1 executed)');
        expect(markdown).toContain('- Photographer decisions: 3');
        expect(markdown).toContain('- Open QA findings: 1');
        expect(markdown).toContain('- Deliverables: 1 active (1 reverted)');
        expect(markdown).toContain('## Creative brief (active)');
        expect(markdown).toContain('- Client: Nadia & Ravi');
        expect(markdown).toContain('**Deliverables**: 30 retouched frames, web + print');
        expect(markdown).toContain('- **#1 retouch** — Lift exposure on hero frames (executed)');
        expect(markdown).toContain('  - IMG_0001.jpg: adjust — Underexposed by ~1EV');
        expect(markdown).toContain('- **#2 cull** — Cull blurred burst frames (rejected)');
        expect(markdown).toContain('- Maya Photographer → **approve** (proposal #1; note: Brighter, keep contrast)');
        expect(markdown).toContain('- Maya Photographer → **reject** (proposal #2)');
        expect(markdown).toContain('- [warning/exposure] Two frames still read flat (open)');
        expect(markdown).toContain('- IMG_0001.jpg — retouched');
        expect(markdown).toContain('  - source: https://blob.example/src.jpg');
        expect(markdown).toContain('  - rendered: https://blob.example/retouched.jpg');
        expect(markdown).toContain('  - adjustments: exposure=10, contrast=5');
        expect(markdown).toContain('  - provenance: lut6key:v1');
        expect(markdown).toContain('  - rendered: https://blob.example/retouched-v0.jpg');
        expect(markdown).toContain('[REVERTED]');
        expect(markdown.endsWith('\n')).toBe(true);
    });

    it('buildReportMarkdown stays honest for an empty session', () => {
        const empty = makeReport();
        empty.proposals = [];
        empty.decisions = [];
        empty.findings = [];
        empty.derivatives = [];

        const markdown = reportModule.buildReportMarkdown(empty);

        expect(markdown).toContain('_No proposals were made in this session._');
        expect(markdown).toContain('_No decisions recorded._');
        expect(markdown).toContain('_No QA findings._');
        expect(markdown).toContain('_No derivatives were executed in this session._');
    });
});
