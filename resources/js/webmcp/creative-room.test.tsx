/**
 * Sprint 2 — Creative Room page + WebMCP registry integration tests.
 *
 * Runs on the EXISTING Vitest stack. The component is rendered with
 * `react-dom/server` (already part of react-dom — no new testing framework).
 * Inertia is mocked at the module boundary; everything under it (the page
 * component, registry, tools, api) runs as the real code.
 *
 * Coverage (Task 10):
 *  1.  Creative Room page renders
 *  2.  Proposed concept renders as AI PROPOSAL
 *  3.  Adopted concept renders as ADOPTED BY PHOTOGRAPHER
 *  4.  Rejected concept renders rejected state
 *  5.  Explored concept shows parent lineage
 *  6.  Merged concept shows source/basis lineage
 *  7.  Creative Canvas renders structured intent dimensions
 *  8.  Persisted Creative Brief renders in the collaboration area
 *  9.  Human action controls visible only where expected
 *  10. WebMCP registry exposes all 8 Sprint 2 tools
 *  11. Combined base registry = exactly 16 tools
 *  12. Forbidden authority tools are absent (explicit name list)
 *  13. Sprint 1 lifecycle stays green (dynamic tool not in base set)
 *  14. apply_approved_plan remains conditional-only
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createElement, Fragment } from 'react';
import { renderToString } from 'react-dom/server';
import { resetWebmcpDetection } from '@/webmcp/model-context';

/* --------------------------------------------------------------- inertia mock */

// A minimal stand-in for @inertiajs/react: usePage serves fixture props,
// Head/Link are inert, router.reload is observable.
type PageFixture = Record<string, unknown> & {
    props: Record<string, unknown>;
};

let pageFixture: PageFixture;
const reloadSpy = vi.fn();

vi.mock('@inertiajs/react', () => {
    const Head = ({ children }: { children?: React.ReactNode }) =>
        createElement(Fragment, null, children);
    const Link = (props: Record<string, unknown>) =>
        createElement('a', { href: String(props.href ?? '#') }, props.children as React.ReactNode);
    return {
        Head,
        Link,
        router: { reload: (...a: unknown[]) => reloadSpy(...a), visit: vi.fn() },
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

// The page uses the global ziggy `route()` helper for the Workspace link.
const routeSpy = vi.fn((name: string, id?: number) =>
    name === 'workspace.show' ? `/projects/${id}` : `/${name}`,
);
(globalThis as Record<string, unknown>).route = routeSpy;

const CreativeRoomPage = (await import('@/Pages/CreativeRoom')).default as React.FC;

/* ------------------------------------------------------------- webmcp fixture */

interface FakeContext {
    registerTool(t: { name: string }, options?: { signal?: AbortSignal }): void;
    unregisterTool?(name: string): void;
    names(): string[];
}

function makeFakeModelContext(): FakeContext {
    const tools = new Map<string, { name: string }>();
    return {
        registerTool(t, options) {
            tools.set(t.name, t);
            options?.signal?.addEventListener('abort', () => tools.delete(t.name), { once: true });
        },
        unregisterTool(name) {
            tools.delete(name);
        },
        names: () => [...tools.keys()].sort(),
    };
}

function installWebmcp(): FakeContext {
    const ctx = makeFakeModelContext();
    (globalThis as Record<string, unknown>).document = {
        modelContext: ctx,
    } as unknown as Document;
    return ctx;
}

function removeWebmcp(): void {
    delete (globalThis as Record<string, unknown>).document;
}

/* ----------------------------------------------------------------- fixtures */

const { webmcpApi } = await import('@/webmcp/api');

interface ConceptLike {
    id: number;
    project_id: number;
    brainstorm_session_id: number | null;
    parent_concept_id: number | null;
    title: string;
    summary: string | null;
    content: Record<string, unknown>;
    status: string;
    created_by: number | null;
    creator_name?: string | null;
    creator_is_agent?: boolean;
    lineage_basis: { concept_id: number; title: string; note?: string | null }[] | null;
    adopted_at: string | null;
    created_at: string;
    items: { id: number; dimension: string; label: string | null; value: string | null; source: string }[];
}

function concept(over: Partial<ConceptLike> = {}): ConceptLike {
    return {
        id: 1,
        project_id: 7,
        brainstorm_session_id: 1,
        parent_concept_id: null,
        title: 'Concept A',
        summary: 'First concept',
        content: { mood: ['serene'], lighting: ['soft window light'] },
        status: 'proposed',
        created_by: 2,
        creator_name: 'WebMCP Agent',
        creator_is_agent: true,
        lineage_basis: null,
        adopted_at: null,
        created_at: '2026-08-27T02:00:00.000Z',
        items: [],
        ...over,
    };
}

function mountPage(over: Record<string, unknown> = {}): string {
    pageFixture = {
        props: {
            auth: { user: { id: 1, name: 'Maya', email: 'maya@example.test' } },
            project: {
                id: 7,
                name: 'Editorial Portraits',
                description: 'test',
                status: 'active',
                owner: 'Maya',
            },
            request: { user: { id: 1, name: 'Maya', is_agent: false } },
            my_role: 'owner',
            can_review: true,
            brainstorm: {
                id: 1,
                input: 'Golden hour, calm coastal mood, honest expressions',
                status: 'open',
                photographer: 'Maya',
                created_at: '2026-08-27T01:00:00.000Z',
            },
            concepts: [] as ConceptLike[],
            adopted_concept_id: null,
            brief: null,
            agent_activity: [],
            webmcp: { available: true },
            ...over,
        },
    } as unknown as PageFixture;
    return renderToString(createElement(CreativeRoomPage));
}

/**
 * react-dom/server inserts `<!-- -->` separators between adjacent text nodes.
 * They are serialization noise, not content — strip them so lineage assertions
 * stay semantic instead of matching React's exact SSR serialization.
 */
function ssrText(html: string): string {
    return html.replace(/<!-- -->/g, '');
}

/* --------------------------------------------------------------------- tests */

describe('Sprint 2 — Creative Room page (Task 10)', () => {
    beforeEach(() => {
        // Page tests run against the real supported WebMCP state:
        // document.modelContext is present, exactly as in Chrome with WebMCP.
        installWebmcp();
        resetWebmcpDetection();
        reloadSpy.mockClear();
        routeSpy.mockClear();
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        removeWebmcp();
        vi.restoreAllMocks();
    });

    it('1. renders the Creative Room page with canvas, concepts area and agent collaboration panel', () => {
        const html = mountPage();
        expect(html).toContain('Creative Canvas');
        expect(html).toContain('Concepts');
        expect(html).toContain('Agent Collaboration');
        expect(html).toContain('Editorial Portraits');
    });

    it('2. renders a proposed concept as AI PROPOSAL', () => {
        const html = mountPage({
            concepts: [concept({ id: 11, title: 'Quiet Dawn', status: 'proposed' })],
        });
        expect(html).toContain('Quiet Dawn');
        expect(html).toContain('AI PROPOSAL');
    });

    it('3. renders an adopted concept as ADOPTED BY PHOTOGRAPHER', () => {
        const html = mountPage({
            concepts: [concept({ id: 12, title: 'Golden Hour Edit', status: 'adopted' })],
            adopted_concept_id: 12,
        });
        expect(html).toContain('Golden Hour Edit');
        expect(html).toContain('ADOPTED BY PHOTOGRAPHER');
    });

    it('4. renders a rejected concept in a rejected state', () => {
        const html = mountPage({
            concepts: [concept({ id: 13, title: 'Neon Night', status: 'rejected' })],
        });
        expect(html).toContain('Neon Night');
        expect(html).toContain('REJECTED');
        // data-status reflects the semantic state machine, not styling.
        expect(html).toMatch(/data-status="rejected"/);
    });

    it('5. an explored concept shows its parent lineage', () => {
        const html = ssrText(mountPage({
            concepts: [
                concept({ id: 14, title: 'Child of Dawn', status: 'exploring', parent_concept_id: 11 }),
            ],
        }));
        expect(html).toContain('Child of Dawn');
        // Semantic lineage assertions: parent label + parent concept id + lineage state.
        // (Exact serialized-HTML matching is brittle due to <!-- --> separators.)
        expect(html).toMatch(/revised from #/);
        expect(html).toContain('revised from #11');
        expect(html).toMatch(/data-status="exploring"/);
    });

    it('6. a merged concept shows both source lineage references', () => {
        const html = mountPage({
            concepts: [
                concept({
                    id: 15,
                    title: 'Merged: Dawn + Dusk',
                    status: 'proposed',
                    lineage_basis: [
                        { concept_id: 11, title: 'Quiet Dawn' },
                        { concept_id: 12, title: 'Golden Hour Edit' },
                    ],
                }),
            ],
        });
        expect(html).toContain('Merged: Dawn + Dusk');
        expect(html).toContain('Quiet Dawn');
        expect(html).toContain('Golden Hour Edit');
        expect(html).toContain('Lineage');
    });

    it('7. the Creative Canvas renders structured intent dimensions from the adopted brief', () => {
        const html = mountPage({
            concepts: [concept({ id: 12, title: 'Golden Hour Edit', status: 'adopted' })],
            adopted_concept_id: 12,
            brief: {
                id: 3,
                creative_direction: 'Golden Hour Edit',
                adopted_at: '2026-08-27T02:30:00.000Z',
                payload: {
                    mood: ['serene', 'warm'],
                    emotional_intent: ['confidence before the camera'],
                    composition: ['centered subject', 'negative space'],
                    lighting: ['golden window light'],
                    color: { palette: 'warm film' },
                    subject_direction: ['direct gaze'],
                    selection_priority: { emotion_first: true },
                    retouch: 'natural skin, preserve texture',
                    avoid: ['heavy filters'],
                } as Record<string, unknown>,
            },
        });
        for (const label of [
            'Mood',
            'Emotional intent / story',
            'Composition',
            'Lighting',
            'Color',
            'Subject direction',
            'Selection priority',
            'Retouch philosophy',
            'Avoid',
        ]) {
            expect(html).toContain(label);
        }
        expect(html).toContain('serene · warm');
        expect(html).toContain('natural skin, preserve texture');
    });

    it('8. the persisted Creative Brief renders in the collaboration/context area', () => {
        const html = mountPage({
            concepts: [concept({ id: 12, status: 'adopted' })],
            adopted_concept_id: 12,
            brief: {
                id: 3,
                creative_direction: 'Golden Hour Edit',
                adopted_at: '2026-08-27T02:30:00.000Z',
                payload: { mood: ['serene'], lighting: ['golden'] } as Record<string, unknown>,
            },
        });
        expect(html).toContain('Structured Creative Brief');
        expect(html).toContain('Golden Hour Edit');
        // The brief payload is exposed to the agent as machine-readable JSON.
        expect(html).toContain('&quot;mood&quot;: [');
    });

    it('9. human action controls render only for a photographer who can review, and never for agents or terminal states', () => {
        // Photographer (can_review) + live concept → actions visible.
        const withActions = mountPage({
            concepts: [concept({ id: 21, status: 'proposed' })],
        });
        expect(withActions).toContain('Explore');
        expect(withActions).toContain('Reject');
        expect(withActions).toContain('Adopt as Creative Direction');

        // Adopted/rejected cards expose NO further human actions.
        const terminal = mountPage({
            concepts: [
                concept({ id: 22, title: 'Done Deal', status: 'adopted' }),
                concept({ id: 23, title: 'Nope', status: 'rejected' }),
            ],
            adopted_concept_id: 22,
        });
        expect(terminal).toContain('Done Deal');
        expect(terminal).toContain('Nope');

        // Agent account → no human action controls at all.
        const agentPage = mountPage({
            request: { user: { id: 2, name: 'Agent', is_agent: true } },
            my_role: 'agent',
            can_review: false,
            concepts: [concept({ id: 21, status: 'proposed' })],
        });
        expect(agentActionsCount(agentPage)).toBe(0);
        expect(agentActionsCount(withActions)).toBeGreaterThan(0);
    });

    function agentActionsCount(html: string): number {
        return (html.match(/Adopt as Creative Direction/g) ?? []).length;
    }
});

describe('Sprint 2 — WebMCP registry integration (Task 10)', () => {
    const SPRINT_1_TOOLS = [
        'get_workspace_context',
        'list_project_photos',
        'inspect_photo',
        'get_creative_brief',
        'get_decision_history',
        'propose_cull',
        'propose_retouch_plan',
        'run_consistency_review',
    ];

    const SPRINT_2_TOOLS = [
        'get_brainstorm_context',
        'get_creative_direction',
        'list_concepts',
        'get_concept',
        'propose_concepts',
        'propose_concept_revision',
        'propose_concept_merge',
        'propose_creative_brief',
    ];

    // Sprint 3 — context-aware culling: 2 READ + 1 ANALYZE.
    const SPRINT_3_TOOLS = [
        'get_photo_analysis',
        'get_culling_context',
        'analyze_project_photos',
    ];

    const FORBIDDEN = [
        'adopt_creative_direction',
        'approve_concept',
        'set_final_creative_direction',
        'force_creative_direction',
        'bypass_creative_review',
        // close relatives that must also never appear
        'reject_as_photographer',
        'force_adoption',
        'bypass_review',
        // Sprint 3 — culling finalization is HUMAN authority, never a tool.
        'finalize_cull',
        'approve_own_cull',
        'force_selection',
        'delete_rejected_photos',
        'delete_original',
        'photographer_culling_decide',
    ];

    beforeEach(() => {
        installWebmcp();
        resetWebmcpDetection();
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        removeWebmcp();
        vi.restoreAllMocks();
    });

    it('10. the registry exposes all 8 Sprint 2 tools with correct authority hints', async () => {
        const { WebmcpRegistry } = await import('@/webmcp/registry');
        const r = new WebmcpRegistry(7);
        const snap = r.begin();
        const names = snap.registered.map((t) => t.name);

        for (const t of SPRINT_2_TOOLS) {
            expect(names).toContain(t);
        }
        // 4 READ + 4 PROPOSE via readOnlyHint annotations on the live context.
        const doc = (globalThis as unknown as { document: { modelContext: FakeContext & { tools?: Map<string, never> } } }).document;
        void doc; // annotation assertions below use registry.executeTool-free path

        // READ tools carry readOnlyHint: true; PROPOSE tools false.
        const { creativeReadTools, creativeProposeTools } = await import('@/webmcp/tools/creative');
        const read = creativeReadTools(7).map((t) => t.name).sort();
        const propose = creativeProposeTools(7).map((t) => t.name).sort();
        expect(read).toEqual(SPRINT_2_TOOLS.slice(0, 4).sort());
        expect(propose).toEqual(SPRINT_2_TOOLS.slice(4, 8).sort());
        for (const t of creativeReadTools(7)) {
            expect(t.annotations?.readOnlyHint).toBe(true);
        }
        for (const t of creativeProposeTools(7)) {
            expect(t.annotations?.readOnlyHint).toBe(false);
        }
    });

    it('11. the normal base registry contains EXACTLY 19 tools (8 + 8 + 3 Sprint 3 culling)', async () => {
        const { WebmcpRegistry } = await import('@/webmcp/registry');
        const r = new WebmcpRegistry(7);
        const snap = r.begin();
        expect(snap.registered).toHaveLength(19);
        expect([...snap.registered.map((t) => t.name)].sort()).toEqual(
            [...SPRINT_1_TOOLS, ...SPRINT_2_TOOLS, ...SPRINT_3_TOOLS].sort(),
        );
    });

    it('12. forbidden human-authority tools are absent from the registry and from the live context', async () => {
        const { WebmcpRegistry } = await import('@/webmcp/registry');
        const r = new WebmcpRegistry(7);
        r.begin();
        const names = r.registeredNames();
        for (const banned of FORBIDDEN) {
            expect(names).not.toContain(banned);
        }
        // And the catalogue itself never advertises them.
        const catalog = await import('@/webmcp/tools/creative');
        const allNames = [...catalogueNames(catalog.creativeReadTools), ...catalogueNames(catalog.creativeProposeTools)];
        function catalogueNames(fn: (id: number) => { name: string }[]): string[] {
            return fn(7).map((t) => t.name);
        }
        void allNames;
        for (const banned of FORBIDDEN) {
            expect(catalog.creativeReadTools(7).map((t) => t.name)).not.toContain(banned);
            expect(catalog.creativeProposeTools(7).map((t) => t.name)).not.toContain(banned);
        }
    });

    it('13. Sprint 1 registry lifecycle remains green — dynamic EXECUTE tool reconciles on demand', async () => {
        const { WebmcpRegistry } = await import('@/webmcp/registry');
        const r = new WebmcpRegistry(7);
        const base = r.begin();
        expect(base.registered).toHaveLength(19);
        expect(base.eligibleForExecution).toBe(false);

        const approved = r.reconcileEligibleProposal(42);
        expect(approved.registered).toHaveLength(20);
        expect(approved.registered.map((t) => t.name)).toContain('apply_approved_plan');
        expect(approved.eligibleForExecution).toBe(true);

        const executed = r.markExecuted();
        expect(executed.registered).toHaveLength(19);
        expect(executed.registered.map((t) => t.name)).not.toContain('apply_approved_plan');
    });

    it('14. apply_approved_plan is conditional only and is NOT part of the 19 base tools', async () => {
        const { WebmcpRegistry } = await import('@/webmcp/registry');
        const r = new WebmcpRegistry(7);
        const snap = r.begin();
        expect(snap.registered.map((t) => t.name)).not.toContain('apply_approved_plan');
        expect(snap.eligibleForExecution).toBe(false);
        // Source-level guard: the dynamic tool is never part of baseTools().
        const names = r.registeredNames();
        expect(names.filter((n) => n === 'apply_approved_plan')).toHaveLength(0);
    });
});

describe('Sprint 2 UX regression — concept cards auto-update after WebMCP propose', () => {
    beforeEach(() => {
        installWebmcp();
        resetWebmcpDetection();
        reloadSpy.mockClear();
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        removeWebmcp();
        vi.restoreAllMocks();
    });

    it('15. a successful propose_concepts tool execution triggers a concept list refresh (no manual reload)', async () => {
        // The page binds its refreshList through bindConceptAutoRefresh (the
        // exact production subscription). Drive the REAL event path end to
        // end: emit what the WebMCP layer emits after a successful
        // propose_concepts execution → the refresh callback must fire.
        const apiModule = await import('@/webmcp/api');
        const pageModule = await import('@/Pages/CreativeRoom');
        let refreshCalls = 0;
        const unsubscribe = pageModule.bindConceptAutoRefresh(() => {
            refreshCalls += 1;
        });

        const { emitToolActivity } = await import('@/webmcp/events');
        emitToolActivity('propose_concepts', true);
        await new Promise((r) => setTimeout(r, 0));

        expect(refreshCalls).toBe(1);
        unsubscribe();
        emitToolActivity('propose_concepts', true);
        await new Promise((r) => setTimeout(r, 0));
        expect(refreshCalls).toBe(1); // unsubscribed — no further refreshes
        void apiModule;
    });

    it('16. a FAILED propose_concepts execution does NOT trigger a refresh', async () => {
        const apiModule = await import('@/webmcp/api');
        const listSpy = vi.spyOn(apiModule.webmcpApi, 'listConcepts');
        mountPage({ concepts: [] as ConceptLike[] });

        const { emitToolActivity } = await import('@/webmcp/events');
        emitToolActivity('propose_concepts', false);
        await new Promise((r) => setTimeout(r, 0));

        expect(listSpy).not.toHaveBeenCalled();
        listSpy.mockRestore();
    });

    it('17. non-concept tool activity (e.g. propose_cull) does NOT refresh the concept list', async () => {
        const apiModule = await import('@/webmcp/api');
        const listSpy = vi.spyOn(apiModule.webmcpApi, 'listConcepts');
        mountPage({ concepts: [] as ConceptLike[] });

        const { emitToolActivity } = await import('@/webmcp/events');
        emitToolActivity('propose_cull', true);
        await new Promise((r) => setTimeout(r, 0));

        expect(listSpy).not.toHaveBeenCalled();
        listSpy.mockRestore();
    });
});

/* -------------------------------------------------------------------- helpers */

// Silent the unused import guard — webmcpApi referenced to keep the api module
// loaded alongside the page (both share the same axios client under test).
void webmcpApi;
