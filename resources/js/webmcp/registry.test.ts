import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { WebmcpRegistry } from './registry';
import { isWebmcpAvailable, resetWebmcpDetection } from './model-context';

/**
 * A recording ModelContext that mimics the modern WebMCP browser API
 * (document.modelContext). It stores the full tool objects so tests can
 * assert on annotations, plus exposes listTools for the diagnostics feed.
 */
interface FakeWebmcp {
    available: boolean;
    registerTool(t: unknown, options?: { signal?: AbortSignal }): void;
    unregisterTool?: (name: string) => void;
    listTools(): { name: string }[];
    /** Test helper: registered tool by name */
    tool(
        name: string,
    ):
        | (Record<string, unknown> & {
              name?: string;
              annotations?: { readOnlyHint?: boolean };
          })
        | undefined;
    names(): string[];
}

function makeFake(withUnregister = true): FakeWebmcp {
    const tools = new Map<string, Record<string, unknown>>();
    const fake: FakeWebmcp = {
        available: true,
        registerTool(t, options) {
            const name = (t as { name: string }).name;
            tools.set(name, t as Record<string, unknown>);
            options?.signal?.addEventListener(
                'abort',
                () => tools.delete(name),
                { once: true },
            );
        },
        listTools: () => [...tools.keys()].map((name) => ({ name })),
        tool: (name) => tools.get(name),
        names: () => [...tools.keys()].sort(),
    };

    if (withUnregister) {
        fake.unregisterTool = (name) => tools.delete(name);
    }

    return fake;
}

/** Install the fake as document.modelContext and reset detection. */
function installDocument(ctx: FakeWebmcp) {
    (globalThis as Record<string, unknown>).document = {
        modelContext: ctx,
    } as unknown as Document;
    resetWebmcpDetection();
}

function removeDocument() {
    delete (globalThis as Record<string, unknown>).document;
    resetWebmcpDetection();
}

describe('WebmcpRegistry lifecycle', () => {
    const SPRINT_1 = [
        'get_workspace_context',
        'list_project_photos',
        'inspect_photo',
        'get_creative_brief',
        'get_decision_history',
        'propose_cull',
        'propose_retouch_plan',
        'run_consistency_review',
    ];

    const SPRINT_2 = [
        'get_brainstorm_context',
        'get_creative_direction',
        'list_concepts',
        'get_concept',
        'propose_concepts',
        'propose_concept_revision',
        'propose_concept_merge',
        'propose_creative_brief',
    ];

    const SPRINT_3 = [
        'get_photo_analysis',
        'get_culling_context',
        'analyze_project_photos',
    ];

    // Certified Sprint 3 inventory: 8 static + 8 static + 3 static = 19.
    const BASE = [...SPRINT_1, ...SPRINT_2, ...SPRINT_3];

    /** No tool may exercise final human authority (culling or creative). */
    const FORBIDDEN = [
        'adopt_creative_direction',
        'approve_concept',
        'reject_as_photographer',
        'set_final_creative_direction',
        'bypass_review',
        'force_adoption',
        // Sprint 3 — culling finalization is HUMAN authority.
        'finalize_cull',
        'approve_own_cull',
        'force_selection',
        'delete_rejected_photos',
        'delete_original',
        'photographer_culling_decide',
    ];

    beforeEach(() => {
        resetWebmcpDetection();
    });

    afterEach(() => {
        removeDocument();
    });

    it('registers exactly the base tools on begin() — no dynamic tool', () => {
        const r = new WebmcpRegistry(1);
        const snap = r.begin();

        const names = snap.registered.map((t) => t.name).sort();
        expect(names).toEqual([...BASE].sort());
        expect(names).not.toContain('apply_approved_plan');
        expect(snap.eligibleForExecution).toBe(false);
        expect(snap.webmcpAvailable).toBe(false); // node has no document
        expect(snap.usingFallback).toBe(true);
    });

    it('drives the real document.modelContext when present, with readOnlyHint annotations', () => {
        const fake = makeFake();
        installDocument(fake);
        resetWebmcpDetection();

        const r = new WebmcpRegistry(7);
        r.begin();

        expect(fake.names()).toEqual([...BASE].sort());
        expect(fake.names()).not.toContain('apply_approved_plan');

        for (const readTool of [
            'get_workspace_context',
            'list_project_photos',
            'inspect_photo',
            'get_creative_brief',
            'get_decision_history',
        ]) {
            expect(fake.tool(readTool)?.annotations?.readOnlyHint).toBe(true);
        }
        for (const proposeTool of ['propose_cull', 'propose_retouch_plan', 'run_consistency_review']) {
            expect(fake.tool(proposeTool)?.annotations?.readOnlyHint).toBe(false);
        }
        expect(fake.tool('apply_approved_plan')).toBeUndefined();

        // Sprint 3 — culling annotations. get_photo_analysis / get_culling_context
        // are genuinely read-only; analyze_project_photos persists observations.
        for (const readTool of ['get_photo_analysis', 'get_culling_context']) {
            expect(fake.tool(readTool)?.annotations?.readOnlyHint).toBe(true);
        }
        expect(fake.tool('analyze_project_photos')?.annotations?.readOnlyHint).toBe(false);

        // Sprint 2 — Creative Room annotations.
        for (const readTool of [
            'get_brainstorm_context',
            'get_creative_direction',
            'list_concepts',
            'get_concept',
        ]) {
            expect(fake.tool(readTool)?.annotations?.readOnlyHint).toBe(true);
        }
        for (const proposeTool of [
            'propose_concepts',
            'propose_concept_revision',
            'propose_concept_merge',
            'propose_creative_brief',
        ]) {
            expect(fake.tool(proposeTool)?.annotations?.readOnlyHint).toBe(false);
        }
        // Human-final authority tools must NEVER be registered.
        for (const banned of FORBIDDEN) {
            expect(fake.names()).not.toContain(banned);
            expect(fake.tool(banned)).toBeUndefined();
        }

        for (const n of fake.names()) {
            expect(
                (fake.tool(n)?.inputSchema as {
                    additionalProperties?: boolean;
                })?.additionalProperties,
            ).toBe(false);
        }
    });

    it('apply_approved_plan appears only after an eligible approved proposal, and disappears on markExecuted', () => {
        const fake = makeFake();
        installDocument(fake);
        resetWebmcpDetection();

        const r = new WebmcpRegistry(1);
        r.begin();

        expect(fake.names()).not.toContain('apply_approved_plan');

        // Photographer approves proposal #42 → reconcile → tool appears.
        const approved = r.reconcileEligibleProposal(42);
        expect(fake.names()).toContain('apply_approved_plan');
        expect(r.isEligibleForExecution()).toBe(true);
        expect(approved.eligibleForExecution).toBe(true);
        const execTool = approved.registered.find((t) => t.name === 'apply_approved_plan');
        expect(execTool?.dynamic).toBe(true);
        expect(execTool?.authority).toBe('EXECUTE');

        // Idempotent while still eligible.
        r.reconcileEligibleProposal(42);
        expect(fake.names()).toContain('apply_approved_plan');

        // Execution completes → unregistered from the live API via its abort path.
        r.markExecuted();
        expect(fake.names()).not.toContain('apply_approved_plan');
        expect(r.isEligibleForExecution()).toBe(false);
        expect(r.registeredNames()).not.toContain('apply_approved_plan');
    });

    it('uses the registration signal when the browser has no explicit unregister API', () => {
        const fake = makeFake(false);
        installDocument(fake);

        const r = new WebmcpRegistry(1);
        r.begin();
        r.reconcileEligibleProposal(42);
        expect(fake.names()).toContain('apply_approved_plan');

        r.markExecuted();

        expect(fake.names()).not.toContain('apply_approved_plan');
        expect(r.registeredNames()).not.toContain('apply_approved_plan');
    });

    it('a null/invalid eligible proposal unregisters the execution tool', () => {
        const r = new WebmcpRegistry(1);
        r.begin();
        r.reconcileEligibleProposal(42);
        expect(r.registeredNames()).toContain('apply_approved_plan');

        r.reconcileEligibleProposal(null);
        expect(r.registeredNames()).not.toContain('apply_approved_plan');

        r.reconcileEligibleProposal(42);
        expect(r.registeredNames()).toContain('apply_approved_plan');
        r.reconcileEligibleProposal(0);
        expect(r.registeredNames()).not.toContain('apply_approved_plan');
    });

    it('dispose() unregisters every tool from the live API', () => {
        const fake = makeFake();
        installDocument(fake);
        resetWebmcpDetection();

        const r = new WebmcpRegistry(1);
        r.begin();
        r.reconcileEligibleProposal(42);
        expect(fake.names().length).toBeGreaterThanOrEqual(9);

        r.dispose();
        expect(fake.names().length).toBe(0);
        expect(r.registeredNames().length).toBe(0);
    });

    it('publishes snapshot updates to subscribers across the lifecycle', () => {
        const r = new WebmcpRegistry(1);
        const seen: string[][] = [];
        r.subscribe((s) => seen.push(s.registered.map((t) => t.name)));

        r.begin();
        r.reconcileEligibleProposal(42);
        r.markExecuted();

        expect(seen.length).toBeGreaterThanOrEqual(3);
        expect(seen.some((names) => names.includes('apply_approved_plan'))).toBe(true);
        expect(seen[seen.length - 1]).not.toContain('apply_approved_plan');
    });

    it('executeTool guards: refuses unregistered / aborted tools, runs registered ones', async () => {
        const r = new WebmcpRegistry(1);
        r.begin();

        const refused = (await r.executeTool('apply_approved_plan', {})) as {
            ok: boolean;
            error: string;
        };
        expect(refused.ok).toBe(false);
        expect(refused.error).toMatch(/not registered/i);

        // After approval the tool is registered; its executor still performs a
        // real network call which fails in node, but the registry guard lets it
        // through and the failure is surfaced as an error result, not a throw.
        r.reconcileEligibleProposal(42);
        const res = (await r.executeTool('apply_approved_plan', {})) as {
            ok: boolean;
            error?: string;
        };
        expect(res.ok).toBe(false);
        expect(res.error).toBeTruthy();
    });

    it('registers Sprint 3 culling tools with correct authority labels', () => {
        const r = new WebmcpRegistry(1);
        const snap = r.begin();

        const byName = new Map(snap.registered.map((t) => [t.name, t]));
        expect(byName.get('get_photo_analysis')?.authority).toBe('READ');
        expect(byName.get('get_culling_context')?.authority).toBe('READ');
        expect(byName.get('analyze_project_photos')?.authority).toBe('ANALYZE');

        // Sprint 1/2 PROPOSE tools keep their label; nothing else claims ANALYZE.
        expect(byName.get('propose_cull')?.authority).toBe('PROPOSE');
        expect(byName.get('propose_concepts')?.authority).toBe('PROPOSE');
        const analyze = snap.registered.filter((t) => t.authority === 'ANALYZE');
        expect(analyze.map((t) => t.name)).toEqual(['analyze_project_photos']);
    });

    it('feature detection prefers document.modelContext and ignores deprecated navigator.modelContext', () => {
        // No document.
        expect(isWebmcpAvailable()).toBe(false);

        // Only deprecated navigator.modelContext present → still false.
        Object.defineProperty(globalThis, 'navigator', {
            value: {
            modelContext: { registerTool: () => {} },
            },
            configurable: true,
        } as PropertyDescriptor);
        resetWebmcpDetection();
        expect(isWebmcpAvailable()).toBe(false);
        Object.defineProperty(globalThis, 'navigator', {
            value: undefined,
            configurable: true,
        });
        resetWebmcpDetection();

        // Modern document.modelContext present → true.
        installDocument(makeFake());
        expect(isWebmcpAvailable()).toBe(true);
    });
});
