/**
 * Sprint 4 — retouch / consistency-QA / creative-memory page + WebMCP registry
 * certification tests.
 *
 * Runs on the EXISTING Vitest stack; the Workspace page is rendered with
 * `react-dom/server` exactly like the Sprint 2/3 suites. Inertia, the layout
 * and `route()` are mocked at the module boundary; everything under them
 * (page component, registry, tools, api) runs as real code.
 *
 * Coverage (certification gate §2):
 *   1. retouch panel renders (ORIGINAL vs APPROVED)
 *   2. original image renders
 *   3. approved derivative renders
 *   4. original and derivative URLs differ
 *   5. AI-original adjustment values render
 *   6. photographer-modified values render
 *   7. executed values render (== photographer-modified)
 *   8. human authority state renders
 *   9. Modify UI renders
 *  10. Approve UI renders
 *  11. Reject UI renders
 *  12. QA finding renders
 *  13. QA severity renders
 *  14. QA WHY/explanation renders (brief-linked)
 *  15. influenced_by renders (retouch + QA)
 *  16. acknowledge action renders (photographer only, not agent)
 *  17. dismiss action renders (photographer only, not agent)
 *  18. Creative Memory renders
 *  19. Creative Memory explicitly does NOT claim ML personalization
 *  20. run_consistency_review is ANALYZE
 *  21. run_consistency_review has readOnlyHint=false (and PERSISTS qa_findings)
 *  22. static tool count = 21
 *  23. apply_approved_plan remains dynamic/conditional (21 → 22 → 21)
 *  24. forbidden human-authority WebMCP tools are absent
 *  25. Sprint 1 registry regression
 *  26. Sprint 2 Creative Room regression
 *  27. Sprint 3 culling UI regression
 *  28. Creative Room auto-refresh regression
 *
 * Assertions target DOM text/testids — never fragile serialized HTML.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createElement, Fragment } from 'react';
import { renderToString } from 'react-dom/server';
import { resetWebmcpDetection } from '@/webmcp/model-context';

/* --------------------------------------------------------------- inertia mock */

let pageFixture: Record<string, unknown> & { props: Record<string, unknown> };

vi.mock('@inertiajs/react', () => {
    const Head = ({ children }: { children?: React.ReactNode }) =>
        createElement(Fragment, null, children);
    return {
        Head,
        Link: (props: Record<string, unknown>) =>
            createElement('a', { href: String(props.href ?? '#') }, props.children as React.ReactNode),
        router: {
            reload: vi.fn(),
            visit: vi.fn(),
            post: (_url: unknown, _opts: unknown, hooks?: { onSuccess?: () => void; onError?: () => void }) => {
                hooks?.onError?.();
                return Promise.resolve();
            },
        },
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
    name === 'workspace.show' ? `/projects/${id}` : `/${name}`;

const WorkspaceModule = await import('@/Pages/Workspace');
const WorkspacePage = WorkspaceModule.default as React.FC;
const { retouchDraftForPhoto } = WorkspaceModule;

/* ------------------------------------------------------------- webmcp fixture */

interface FakeContext {
    registerTool(t: { name: string; annotations?: { readOnlyHint?: boolean } }, options?: { signal?: AbortSignal }): void;
    unregisterTool?(name: string): void;
    names(): string[];
    tool(name: string): { name: string; annotations?: { readOnlyHint?: boolean } } | undefined;
}

function makeFakeModelContext(): FakeContext {
    const tools = new Map<string, { name: string; annotations?: { readOnlyHint?: boolean } }>();
    return {
        registerTool(t, options) {
            tools.set(t.name, t);
            options?.signal?.addEventListener('abort', () => tools.delete(t.name), { once: true });
        },
        unregisterTool(name) {
            tools.delete(name);
        },
        names: () => [...tools.keys()].sort(),
        tool: (name) => tools.get(name),
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

/* ------------------------------------------------------------------- fixtures */

const PHOTOS = [
    { id: 101, filename: '02-soft-emotive-gaze.jpg', url: '/storage/p/02.jpg', mime: 'image/jpeg', width: 960, height: 640, size_bytes: 1, selection_state: 'selected', retouch_state: 'none' },
    { id: 102, filename: '04-posed-studio-portrait.jpg', url: '/storage/p/04.jpg', mime: 'image/jpeg', width: 960, height: 640, size_bytes: 1, selection_state: 'unreviewed', retouch_state: 'none' },
];

const OBSERVATION = {
    photo_id: 101,
    technical: {
        sharpness: { assessment: 'slightly_soft', confidence: 0.86 },
        exposure: { assessment: 'acceptable', confidence: 0.9 },
        motion_blur: { assessment: 'none', confidence: 0.88 },
        highlight_clipping: { assessment: 'safe', confidence: 0.91 },
        eyes_open: null,
    },
    creative: {
        expression: 'strong',
        candidness: 'mostly_candid',
        environmental_storytelling: 'present',
        mood: ['intimate', 'quiet'],
        compositional_fit: 'strong',
        emotion_strength: 'strong',
    },
    provider: 'demo_pixel_stats',
    provenance: 'deterministic_on_device_pixel_analysis',
    similarity_group: null,
    technical_provenance: 'pixel_analysis',
    creative_provenance: 'demo_sidecar_annotation',
};

const REC_101 = {
    photo_id: 101,
    recommendation: 'keep' as const,
    confidence: 0.81,
    technical_rationale: 'Technically, this frame is slightly soft.',
    creative_rationale: 'Creatively, this frame is emotionally strong expression, mood matches the adopted direction (intimate, quiet).',
    tradeoff:
        'This frame is slightly soft, but its strong creative fit carries it: the adopted Creative Brief prioritizes emotional authenticity over technical perfection, so it remains a keep.',
    influenced_by: ['selection_priority.emotion', 'avoid.overly_posed', 'mood.alignment'],
    photo: { id: 101, filename: '02-soft-emotive-gaze.jpg', url: '/storage/p/02.jpg', selection_state: 'selected', original_name: '02-soft-emotive-gaze.jpg' },
    similarity_group: null,
    similarity_group_size: 1,
};

const CULLING_CONTEXT = {
    project_id: 7,
    has_direction: true,
    provider: 'demo_pixel_stats',
    provenance: 'deterministic_on_device_pixel_analysis',
    context: {
        photos_observed: 2,
        duplicate_groups: [],
        adopted_concept: 'Documentary Intimacy (emotion over perfection)' as string | null,
        selection_priority: { emotion: 'primary', technical: 'secondary' } as unknown,
    },
    recommendations: [REC_101],
};

/** Sprint 4 retouch truth card — three-layer history, executed state. */
const RETOUCH_CARD = {
    proposal_id: 501,
    status: 'executed',
    photo: { id: 101, filename: '02-soft-emotive-gaze.jpg', width: 960, height: 640 },
    original: { url: '/storage/p/02.jpg', sha256: 'a'.repeat(64) },
    derivative: {
        url: '/storage/derivatives/p/02-approved-render.jpg',
        sha256: 'b'.repeat(64),
        storage_path: 'derivatives/p/02-approved-render.jpg',
        adjustments: { exposure: 0.25, warmth: 0.08 },
        provenance: 'approved_render',
        proposal_id: 501,
    },
    agent_original: {
        params: { exposure: 0.3, warmth: 0.22 },
        influenced_by: ['brief.retouch.restrained_warmth', 'brief.tonality.natural_skin'],
        brief_aware: true,
        note: null,
    },
    photographer_modification: { adjustments: { exposure: 0.25, warmth: 0.08 }, note: 'Softer warmth.' },
    executed: { params: { exposure: 0.25, warmth: 0.08 }, at: '2026-08-27T12:00:00.000Z' },
};

/** Sprint 4 persisted QA finding (open, brief-linked). */
const QA_FINDINGS = [
    {
        id: 91,
        severity: 'medium',
        category: 'warmth_consistency',
        message: 'Warmth on 02-soft-emotive-gaze.jpg runs above the selected-set expectation.',
        photo_id: 101,
        status: 'open',
        details: {
            observation: 'warmth +0.60 vs selected-set median +0.10',
            expected: 'restrained warmth per the adopted brief',
            explanation: 'The adopted Creative Brief calls for restrained warmth; this frame is notably warmer than the rest of the set.',
            influenced_by: ['brief.retouch.restrained_warmth', 'set.warmth_median'],
            recommendation: 'Reduce warmth on this frame or acknowledge the intentional exception.',
        },
    },
];

/** Sprint 4 Creative Memory lessons (photographer-authored). */
const CREATIVE_MEMORIES = [
    {
        id: 5,
        kind: 'lesson',
        lesson: 'Less warm.',
        photographer: 'Maya',
        created_at: '2026-08-27T10:00:00.000Z',
    },
];

const RETOUCH_PENDING_PROPOSAL = {
    id: 502,
    type: 'retouch',
    status: 'pending_review',
    summary: 'Warmth-forward exposure pass for the selected frame.',
    created_by: 'Agent',
    created_at: '2026-08-27T09:00:00.000Z',
    reviewed_at: null,
    executed_at: null,
    items: [
        {
            id: 2,
            photo_id: 101,
            kind: 'retouch',
            action: 'exposure',
            rationale: 'Balanced exposure pass.',
            params: { exposure: 0.3, warmth: 0.22 },
            status: 'pending',
            result: null,
        },
    ],
};

const RETOUCH_EXECUTED_PROPOSAL = {
    id: 501,
    type: 'retouch',
    status: 'executed',
    summary: 'Exposure retouch executed with photographer-modified values.',
    created_by: 'Agent',
    created_at: '2026-08-27T08:00:00.000Z',
    reviewed_at: '2026-08-27T08:30:00.000Z',
    executed_at: '2026-08-27T12:00:00.000Z',
    items: [
        {
            id: 1,
            photo_id: 101,
            kind: 'retouch',
            action: 'exposure',
            rationale: 'Balanced exposure pass.',
            params: { exposure: 0.25, warmth: 0.08 },
            status: 'completed',
            result: { derivative: 'derivatives/p/02-approved-render.jpg' },
        },
    ],
};

const { webmcpApi } = await import('@/webmcp/api');

// Network boundary mocks (SSR does not run effects, but keeps the contract honest).
vi.spyOn(webmcpApi, 'getCullingContext').mockResolvedValue({
    ok: true, status: 200, data: CULLING_CONTEXT, error: null,
});
vi.spyOn(webmcpApi, 'getPhotoAnalysis').mockResolvedValue({
    ok: true, status: 200,
    data: { project_id: 7, photo: PHOTOS[0], observation: OBSERVATION, recommendation: REC_101 },
    error: null,
});
vi.spyOn(webmcpApi, 'inspectPhoto').mockResolvedValue({ ok: false, status: 404, data: null, error: 'stubbed' });
vi.spyOn(webmcpApi, 'getWorkspaceContext').mockResolvedValue({
    ok: true, status: 200,
    data: {
        project: { id: 7, name: 'S4', status: 'active', description: null },
        brief: null,
        counts: { total: 2, selected: 1, culled: 0, unreviewed: 1 },
        proposals: { pending: 1, approved_unexecuted: 0 },
        qa: { open: 1 },
        webmcp_available: true,
        generated_at: '2026-08-27T04:00:00.000Z',
    },
    error: null,
});
vi.spyOn(webmcpApi, 'listProjectPhotos').mockResolvedValue({
    ok: true, status: 200, data: { project_id: 7, count: 2, photos: PHOTOS }, error: null,
});
const runReviewSpy = vi.spyOn(webmcpApi, 'runConsistencyReview').mockResolvedValue({
    ok: true, status: 201,
    data: { project_id: 7, scope: 'selected', photos_checked: 2, created_findings: [{ id: 91, severity: 'medium', category: 'warmth_consistency', message: 'Warmth drifts above the set expectation.', photo_id: 101 }] },
    error: null,
});
const proposeRetouchSpy = vi.spyOn(webmcpApi, 'proposeRetouchPlan').mockResolvedValue({
    ok: true, status: 201,
    data: { proposal: RETOUCH_PENDING_PROPOSAL as unknown as Awaited<ReturnType<typeof webmcpApi.proposeRetouchPlan>> extends { data: infer D } ? D extends { proposal: infer P } ? { proposal: P } : never : never },
    error: null,
} as never);

/* ------------------------------------------------------------------- helpers */

function baseProps(over: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        auth: { user: { id: 1, name: 'Maya', email: 'maya@example.test' } },
        project: { id: 7, name: 'Sprint 4 Certification — Retouch + QA Demo', description: 'demo', status: 'active', owner: 'Maya' },
        brief: { client: 'Demo', shoot_date: null, location: null, creative_direction: 'Documentary intimacy', tonality_notes: 'modern neutral, restrained warmth', deliverables: null },
        photos: PHOTOS,
        proposals: [RETOUCH_PENDING_PROPOSAL, RETOUCH_EXECUTED_PROPOSAL],
        decisions: [],
        activity: [],
        request: { user: { id: 1, name: 'Maya', is_agent: false } },
        permissions: { can_upload: true, can_photographer_act: true, can_execute: true },
        webmcp: { available: true },
        retouchCard: RETOUCH_CARD,
        qaFindings: QA_FINDINGS,
        creativeMemories: CREATIVE_MEMORIES,
        ...over,
    };
}

function mount(props: Record<string, unknown>, initial: { culling?: unknown; analysis?: unknown } = {}): string {
    pageFixture = { props };
    const Page = WorkspacePage as unknown as React.FC<Record<string, unknown>>;
    return renderToString(
        createElement(Page, {
            initialCulling: initial.culling ?? null,
            initialAnalysis: initial.analysis ?? null,
        }),
    );
}

/** SSR text without comment-node artifacts. */
const text = (html: string): string => html.replace(/<!-- -->/g, '');

beforeEach(() => {
    installWebmcp();
    resetWebmcpDetection();
    vi.clearAllMocks();
    // restore default resolved values after clearAllMocks
    (webmcpApi.getCullingContext as ReturnType<typeof vi.fn>).mockResolvedValue({ ok: true, status: 200, data: CULLING_CONTEXT, error: null });
    (webmcpApi.runConsistencyReview as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true, status: 201,
        data: { project_id: 7, scope: 'selected', photos_checked: 2, created_findings: [{ id: 91, severity: 'medium', category: 'warmth_consistency', message: 'Warmth drifts above the set expectation.', photo_id: 101 }] },
        error: null,
    });
});

afterEach(() => {
    removeWebmcp();
    resetWebmcpDetection();
});

describe('Sprint 4 — Workspace retouch / QA / creative-memory UI + registry certification', () => {
    it('1. renders the retouch panel with ORIGINAL vs EXECUTED DERIVATIVE surfaces', () => {
        const html = mount(baseProps());
        expect(html).toContain('data-testid="retouch-panel"');
        expect(text(html)).toContain('ORIGINAL');
        expect(text(html)).toContain('EXECUTED DERIVATIVE');
        expect(html).toContain('data-testid="before-after"');
    });

    it('2. renders the original image', () => {
        const html = mount(baseProps());
        expect(html).toContain('data-testid="original-image"');
        expect(html).toContain('src="/storage/p/02.jpg"');
    });

    it('3. renders the approved derivative image', () => {
        const html = mount(baseProps());
        expect(html).toContain('data-testid="derivative-image"');
        expect(html).toContain('src="/storage/derivatives/p/02-approved-render.jpg"');
    });

    it('3b. renders an explicit error when an executed retouch has no derivative', () => {
        const html = mount(baseProps({
            retouchCard: { ...RETOUCH_CARD, derivative: null },
        }));
        expect(html).toContain('data-testid="retouch-render-error"');
        expect(text(html)).toContain('Retouch render failed');
        expect(text(html)).toContain('no approved derivative was stored');
        expect(html).toContain('data-testid="workspace-notify"');
        expect(html).toContain('aria-label="Dismiss notification"');
    });

    it('3c. gives cull selection an accessible photo name and 44px target', () => {
        const html = mount(baseProps());
        expect(html).toContain('aria-label="Select 02-soft-emotive-gaze.jpg for culling"');
        expect(html).toContain('min-h-11 min-w-11');
    });

    it('3d. B2 only exposes a card for the selected photo and states when none exists', () => {
        expect(WorkspaceModule.retouchCardForSelectedPhoto(RETOUCH_CARD, 101)).toBe(RETOUCH_CARD);
        expect(WorkspaceModule.retouchCardForSelectedPhoto(RETOUCH_CARD, 102)).toBeNull();

        const html = renderToString(
            createElement(WorkspaceModule.RetouchTruthCard, { card: null }),
        );
        expect(text(html)).toContain('No retouch recorded for this photo.');
    });

    it('4. original and derivative URLs differ (two distinct sources)', () => {
        const html = mount(baseProps());
        expect(html).toContain('src="/storage/p/02.jpg"');
        expect(html).toContain('src="/storage/derivatives/p/02-approved-render.jpg"');
        expect(RETOUCH_CARD.original.url).not.toBe(RETOUCH_CARD.derivative.url);
    });

    it('5. renders the AI-original adjustment values (agent proposal layer)', () => {
        const html = mount(baseProps());
        expect(html).toContain('data-testid="layer-agent-original"');
        expect(text(html)).toContain('AI PROPOSAL');
        expect(text(html)).toContain('+0.30');
        expect(text(html)).toContain('+0.22');
        expect(html).toContain('data-testid="ai-proposed-values"');
    });

    it('6. renders the photographer-modified values (human modification layer)', () => {
        const html = mount(baseProps());
        expect(html).toContain('data-testid="layer-photographer-modified"');
        expect(text(html)).toContain('PHOTOGRAPHER MODIFIED');
        expect(text(html)).toContain('+0.25');
        expect(text(html)).toContain('+0.08');
        expect(html).toContain('data-testid="photographer-modified-values"');
    });

    it('7. renders AI and executed values in stable parallel columns', () => {
        const html = mount(baseProps());
        expect(html).toContain('data-testid="layer-executed"');
        expect(html).toContain('data-testid="retouch-value-comparison"');
        expect(text(html)).toContain('FINAL APPROVED VALUES');
        expect(html).toContain('data-testid="ai-adjustment-exposure"');
        expect(html).toContain('data-testid="final-adjustment-exposure"');
        expect(html).toContain('data-testid="ai-adjustment-warmth"');
        expect(html).toContain('data-testid="final-adjustment-warmth"');
        expect(text(html)).toContain('+0.30');
        expect(text(html)).toContain('+0.25');
        expect(text(html)).not.toContain('+0.3 exposure');
        expect(html).toContain('data-testid="final-approved-values"');
    });

    it('8. renders the human authority state (approval, not agent execution)', () => {
        const html = mount(baseProps());
        expect(html).toContain('data-testid="human-authority-status"');
        expect(text(html)).toContain('Executed derivative');
        expect(text(html)).toContain('approved by photographer');
        expect(html).toContain('data-testid="approval-check"');
    });

    it('8b. labels an approved preview as not yet executed', () => {
        const html = mount(baseProps({
            retouchCard: {
                ...RETOUCH_CARD,
                status: 'approved',
                derivative: null,
                executed: null,
            },
        }));

        expect(text(html)).toContain('APPROVED PREVIEW');
        expect(text(html)).toContain('awaiting execution');
        expect(text(html)).not.toContain('EXECUTED DERIVATIVE');
        expect(html).toContain('data-testid="derivative-placeholder"');
    });

    it('8d. labels a photographer-modified proposal as awaiting approval', () => {
        const html = mount(baseProps({
            retouchCard: {
                ...RETOUCH_CARD,
                status: 'modified',
                derivative: null,
                executed: null,
            },
        }));

        expect(text(html)).toContain('Photographer-modified preview — awaiting photographer approval.');
        expect(text(html)).not.toContain('Approved preview — awaiting execution');
    });

    it('8c. keeps hashes and raw storage paths inside accessible technical details', () => {
        const html = mount(baseProps());
        const detailsStart = html.indexOf('<details');
        const evidenceStart = html.indexOf('data-testid="retouch-evidence"');

        expect(detailsStart).toBeGreaterThan(-1);
        expect(evidenceStart).toBeGreaterThan(detailsStart);
        expect(html.slice(0, detailsStart)).not.toContain('Raw storage path:');
        expect(html).toContain('Technical details');
        expect(html).toContain('data-testid="original-sha"');
        expect(html).toContain('data-testid="derivative-sha"');
    });

    it('9. renders the Modify UI for the photographer (human-only adjustment editing)', () => {
        const html = mount(baseProps());
        expect(text(html)).toContain('Modify');
        expect(text(html)).toContain('Reject');
        // the photographer-signed-in hint renders for the photographer, never the agent
        expect(text(html)).toContain('You are signed in as photographer');
        const agentHtml = mount(baseProps({
            request: { user: { id: 2, name: 'Agent', is_agent: true } },
            permissions: { can_upload: false, can_photographer_act: false, can_execute: true },
        }));
        expect(text(agentHtml)).not.toContain('You are signed in as photographer');
    });

    it('9b. (B3) executed retouch proposals expose a photographer-only Revert gate', () => {
        const photographer = mount(baseProps());
        expect(photographer).toContain('data-testid="proposal-revert-501"');
        expect(photographer).toContain('Revert execution');

        const agent = mount(baseProps({
            request: { user: { id: 2, name: 'Agent', is_agent: true } },
            permissions: { can_upload: false, can_photographer_act: false, can_execute: true },
        }));
        expect(agent).not.toContain('data-testid="proposal-revert-501"');
    });

    it('10. renders the Approve UI for a pending retouch proposal', () => {
        const html = mount(baseProps());
        expect(text(html)).toContain('Approve');
        expect(html).toContain('Propose Retouch Plan'); // agent proposal surface exists
    });

    it('10b. hides review actions for an empty draft proposal', () => {
        const html = mount(baseProps({
            proposals: [{
                ...RETOUCH_PENDING_PROPOSAL,
                id: 503,
                type: 'cull',
                status: 'draft',
                items: [],
            }],
        }));

        expect(html).toContain('0 item(s): awaiting agent generation');
        expect(html).not.toMatch(/>\s*Approve\s*</);
        expect(html).not.toMatch(/>\s*Reject\s*</);
    });

    it('10c. exposes editable agent proposal inputs and uses bounded photo values', () => {
        const html = mount(baseProps({
            request: { user: { id: 2, name: 'Agent', is_agent: true } },
            permissions: { can_upload: false, can_photographer_act: false, can_execute: true },
        }));
        expect(html).toContain('data-testid="cull-rationale"');
        expect(html).toContain('data-testid="retouch-exposure"');
        expect(html).toContain('data-testid="retouch-contrast"');
        expect(retouchDraftForPhoto({ analysis: { exposure: 0.8, contrast: -0.4 } })).toEqual({ exposure: 0.8, contrast: -0.4 });
        expect(retouchDraftForPhoto({ exposure: 2, contrast: -2 })).toEqual({ exposure: 1, contrast: -1 });
    });

    it('11. renders the Reject UI for a pending retouch proposal', () => {
        const html = mount(baseProps());
        expect(text(html)).toContain('Reject');
    });

    it('12. renders the persisted QA finding with its category', () => {
        const html = mount(baseProps());
        expect(html).toContain('data-testid="qa-panel"');
        expect(html).toContain('data-testid="qa-finding-91"');
        expect(text(html)).toContain('warmth consistency');
    });

    it('13. renders the QA severity badge (MEDIUM)', () => {
        const html = mount(baseProps());
        expect(html).toContain('data-testid="qa-severity-91"');
        expect(text(html)).toContain('MEDIUM');
    });

    it('14. renders the QA WHY/explanation tied to the Creative Brief', () => {
        const html = mount(baseProps());
        expect(html).toContain('data-testid="qa-why-91"');
        expect(text(html)).toContain('WHY:');
        expect(text(html)).toContain('The adopted Creative Brief calls for restrained warmth');
        expect(html).toContain('data-testid="qa-status-91"');
        expect(text(html)).toContain('open');
    });

    it('15. renders influenced_by paths (retouch brief influence + QA finding refs)', () => {
        const html = mount(baseProps());
        expect(html).toContain('data-testid="retouch-influenced-by"');
        expect(text(html)).toContain('brief.retouch.restrained_warmth');
        expect(html).toContain('data-testid="qa-influenced-91"');
        expect(text(html)).toContain('influenced_by');
        expect(text(html)).toContain('set.warmth_median');
    });

    it('16. renders the acknowledge action for the photographer and NOT for the agent', () => {
        const html = mount(baseProps());
        expect(text(html)).toContain('Acknowledge');
        const agentHtml = mount(baseProps({
            request: { user: { id: 2, name: 'Agent', is_agent: true } },
            permissions: { can_upload: false, can_photographer_act: false, can_execute: true },
        }));
        expect(text(agentHtml)).not.toContain('Acknowledge');
        expect(text(agentHtml)).toContain('QA actions are photographer authority');
    });

    it('17. renders the dismiss action for the photographer', () => {
        const html = mount(baseProps());
        expect(text(html)).toContain('Dismiss');
    });

    it('18. renders Creative Memory with the persisted lesson', () => {
        const html = mount(baseProps());
        expect(html).toContain('data-testid="creative-memory-panel"');
        expect(text(html)).toContain('Creative Memory');
        expect(html).toContain('data-testid="memory-list"');
        expect(text(html)).toContain('Less warm.');
        expect(text(html)).toContain('Maya');
    });

    it('19. Creative Memory explicitly does NOT claim ML personalization', () => {
        const html = mount(baseProps());
        // honest deterministic framing is present…
        expect(text(html)).toContain('deterministic context');
        expect(text(html)).toContain('this is not machine-learned personalization');
        // …and no positive ML/taste-learning claim appears anywhere
        expect(html).not.toContain('machine-learned taste');
        expect(html).not.toContain('ML personalization');
        expect(html).not.toMatch(/learns? your (personal )?(taste|style|preferences)/i);
        expect(html).not.toMatch(/\bML-powered\b/i);
    });

    it('19b. React #31 regression: a {id,name} photographer relation object renders as its name, never as an object child', () => {
        // The EXACT payload shape that crashed the live Workspace (Minified
        // React error #31, "object with keys {id,name}"): the store endpoint
        // used to return the eager-loaded photographer relation object, which
        // was optimistically prepended and rendered as a React child.
        const crashedShape = [{
            id: 6,
            kind: 'lesson',
            lesson: 'Less warm.',
            photographer: { id: 3, name: 'Maya' } as unknown as string,
            created_at: '2026-08-27T10:00:00.000Z',
        }];
        // renderToString THROWS on an object child (that is how #31 reached
        // the console as an uncaught error) — the mount itself is the
        // regression assertion.
        const html = mount(baseProps({ creativeMemories: crashedShape }));
        expect(html).toContain('data-testid="creative-memory-panel"');
        expect(text(html)).toContain('Less warm.');
        // The human-readable name is shown — not [object Object], not raw JSON.
        expect(text(html)).toContain('Maya');
        expect(html).not.toContain('[object Object]');
        expect(html).not.toContain('&quot;id&quot;:3');
        // Mixed lists (persisted string rows + optimistic object rows) render too.
        const mixed = mount(baseProps({ creativeMemories: [...CREATIVE_MEMORIES, ...crashedShape] }));
        expect(text(mixed)).toContain('Maya');
        expect(mixed).not.toContain('[object Object]');
    });

    it('20. run_consistency_review carries ANALYZE authority in the registry', async () => {
        const { WebmcpRegistry } = await import('@/webmcp/registry');
        const r = new WebmcpRegistry(7);
        const snap = r.begin();
        const qa = snap.registered.find((t) => t.name === 'run_consistency_review');
        expect(qa?.authority).toBe('ANALYZE');
    });

    it('21. run_consistency_review has readOnlyHint=false and describes persisting qa_findings', async () => {
        const { qaTools } = await import('@/webmcp/tools/qa');
        const tool = qaTools(7).find((t) => t.name === 'run_consistency_review');
        expect(tool?.annotations?.readOnlyHint).toBe(false);
        expect(tool?.description).toContain('PERSISTS qa_findings');
        expect(tool?.description).toContain('ANALYZE authority');
    });

    it('22. the static registry contains EXACTLY 21 tools', async () => {
        const { WebmcpRegistry } = await import('@/webmcp/registry');
        const r = new WebmcpRegistry(7);
        const snap = r.begin();
        expect(snap.registered).toHaveLength(21);
        expect(snap.registered.filter((t) => t.name === 'apply_approved_plan')).toHaveLength(0);
    });

    it('23. apply_approved_plan remains dynamic/conditional (21 → 22 → 21)', async () => {
        const { WebmcpRegistry } = await import('@/webmcp/registry');
        const r = new WebmcpRegistry(7);
        r.begin();
        expect(r.registeredNames()).toHaveLength(21);
        expect(r.registeredNames()).not.toContain('apply_approved_plan');
        const approved = r.reconcileEligibleProposal(42);
        expect(approved.registered).toHaveLength(22);
        expect(approved.registered.map((t) => t.name)).toContain('apply_approved_plan');
        const executed = r.markExecuted();
        expect(executed.registered).toHaveLength(21);
        expect(executed.registered.map((t) => t.name)).not.toContain('apply_approved_plan');
    });

    it('24. NO human-authority retouch/final WebMCP tools exist anywhere in the catalogue', async () => {
        const { WebmcpRegistry } = await import('@/webmcp/registry');
        const { workspaceTools } = await import('@/webmcp/tools/workspace');
        const { proposalTools } = await import('@/webmcp/tools/proposals');
        const { qaTools } = await import('@/webmcp/tools/qa');
        const { creativeReadTools, creativeProposeTools } = await import('@/webmcp/tools/creative');
        const { cullingReadTools } = await import('@/webmcp/tools/culling');
        const { buildApplyApprovedPlanTool } = await import('@/webmcp/tools/execution');

        const allNames = [
            ...workspaceTools(7),
            ...proposalTools(7),
            ...qaTools(7),
            ...creativeReadTools(7),
            ...creativeProposeTools(7),
            ...cullingReadTools(7),
            buildApplyApprovedPlanTool(7, 1),
        ].map((t) => t.name);
        const registryNames = new WebmcpRegistry(7).begin().registered.map((t) => t.name);

        const forbidden = [
            'approve_retouch',
            'approve_own_retouch',
            'photographer_modify_retouch',
            'photographer_approve_retouch',
            'force_retouch',
            'finalize_retouch',
            'final_delivery',
            'mark_project_complete',
            'delete_original',
            'overwrite_original',
        ];
        for (const name of forbidden) {
            expect(allNames).not.toContain(name);
            expect(allNames.join(' ')).not.toContain(name);
            expect(registryNames).not.toContain(name);
        }
    });

    it('25. Sprint 1 regression: workspace/proposal/qa tools remain registered', async () => {
        const { WebmcpRegistry } = await import('@/webmcp/registry');
        const { workspaceTools } = await import('@/webmcp/tools/workspace');
        const { proposalTools } = await import('@/webmcp/tools/proposals');
        const { qaTools } = await import('@/webmcp/tools/qa');
        const s1 = [...workspaceTools(7), ...proposalTools(7), ...qaTools(7)];
        expect(s1).toHaveLength(10);
        const r = new WebmcpRegistry(7);
        const snap = r.begin();
        expect(r.registeredNames()).toEqual(expect.arrayContaining(s1.map((t) => t.name)));
        expect(snap.registered).toHaveLength(21);
    });

    it('26. Sprint 2 regression: all 8 creative tools remain registered', async () => {
        const { WebmcpRegistry } = await import('@/webmcp/registry');
        const { creativeReadTools, creativeProposeTools } = await import('@/webmcp/tools/creative');
        const s2 = [...creativeReadTools(7), ...creativeProposeTools(7)].map((t) => t.name);
        expect(s2).toHaveLength(8);
        const r = new WebmcpRegistry(7);
        r.begin();
        for (const t of s2) expect(r.registeredNames()).toContain(t);
    });

    it('27. Sprint 3 regression: culling UI still renders recommendations', () => {
        const html = mount(baseProps(), { culling: CULLING_CONTEXT, analysis: { project_id: 7, photo: PHOTOS[0], observation: OBSERVATION, recommendation: REC_101 } });
        expect(html).toContain('Context-Aware Culling');
        expect(html).toContain('KEEP');
        expect(html).toContain('data-testid="recommendation-badge"');
    });

    it('28. Creative Room auto-refresh module remains green (regression)', async () => {
        const { emitToolActivity, mutatesConceptList, onConceptMutatingActivity } = await import('@/webmcp/events');
        expect(mutatesConceptList('propose_concepts')).toBe(true);
        expect(mutatesConceptList('propose_retouch_plan')).toBe(false);

        const seen: string[] = [];
        const off = onConceptMutatingActivity((d) => seen.push(d.tool));
        emitToolActivity('propose_concepts', true);
        off();
        emitToolActivity('propose_retouch_plan', true); // unsubscribed + non-mutating
        expect(seen).toEqual(['propose_concepts']);
    });

    it('29. run_consistency_review is wired through the QA review API (spy contract)', async () => {
        const res = await webmcpApi.runConsistencyReview(7, 'selected');
        expect(runReviewSpy).toHaveBeenCalledWith(7, 'selected');
        expect(res.data?.created_findings).toHaveLength(1);
        expect(res.data?.created_findings[0].category).toBe('warmth_consistency');
    });
});
