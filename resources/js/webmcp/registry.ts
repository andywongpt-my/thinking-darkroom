/**
 * WebMCP registry — the orchestrator owning tool lifecycle for ONE project
 * workspace.
 *
 * Responsibilities:
 *  - Register the fixed READ/PROPOSE base tools on `document.modelContext`
 *    (or the in-memory fallback when WebMCP is unavailable).
 *  - Dynamically register `apply_approved_plan` (EXECUTE) ONLY while the
 *    project holds an approved, unexecuted proposal, and unregister it via its
 *    AbortController the moment it is executed / rejected / no longer valid.
 *  - Emit a live diagnostics feed (tool name · authority · registeredAt) so
 *    the UI panel can demonstrate the lifecycle exactly.
 */

import { registerTool, unregisterTool, isWebmcpAvailable, createInMemoryModelContext, getModelContext } from './model-context';
import type { ModelContext, ModelContextTool, RegisteredToolMeta, WebmcpAuthority } from './tool-types';
import { workspaceTools } from './tools/workspace';
import { proposalTools } from './tools/proposals';
import { qaTools } from './tools/qa';
import { creativeReadTools, creativeProposeTools } from './tools/creative';
import { cullingReadTools } from './tools/culling';
import { buildApplyApprovedPlanTool } from './tools/execution';

export interface RegistrySnapshot {
    registered: RegisteredToolMeta[];
    webmcpAvailable: boolean; // real document.modelContext present
    usingFallback: boolean; // in-memory demo fallback active
    eligibleForExecution: boolean;
}

export type RegistryListener = (snapshot: RegistrySnapshot) => void;

interface TrackedTool {
    tool: ModelContextTool;
    authority: WebmcpAuthority;
    dynamic: boolean;
    abort: AbortController;
    registeredAt: string;
}

export class WebmcpRegistry {
    private readonly projectId: number;
    private readonly context: ModelContext;
    private readonly webmcpAvailable: boolean;
    private readonly usingFallback: boolean;
    private tools = new Map<string, TrackedTool>();
    private eligible = false;
    private listeners = new Set<RegistryListener>();
    /** Named AbortControllers for dynamic tools (keyed by proposal id). */
    private dynamicAborts = new Map<number, AbortController>();
    /** Proposal id the currently-registered dynamic tool is bound to. */
    private boundProposalId: number | null = null;

    constructor(projectId: number) {
        this.projectId = projectId;
        this.webmcpAvailable = isWebmcpAvailable();
        // When real WebMCP is absent we still drive an in-memory ModelContext
        // so the diagnostics panel demonstrates lifecycle truthfully.
        this.context = this.webmcpAvailable ? getModelContext()! : createInMemoryModelContext();
        this.usingFallback = !this.webmcpAvailable;
    }

    /** Register the always-on base tools. Returns snapshot. */
    begin(): RegistrySnapshot {
        for (const tool of this.baseTools()) {
            this.track(tool, this.authorityFor(tool.name), false, new AbortController());
        }
        return this.snapshot();
    }

    /** True when the current project has an eligible approved proposal. */
    isEligibleForExecution(): boolean {
        return this.eligible;
    }

    /**
     * Reconcile the dynamic EXECUTE tool with workspace state.
     * Call after approval, rejection, execution, or any state refresh.
     */
    reconcileEligibleProposal(proposalId: number | null): RegistrySnapshot {
        const eligible = proposalId !== null && Number.isFinite(proposalId) && proposalId > 0;
        this.eligible = eligible;

        // Re-register when the bound proposal changed (Sol P1-9): the tool's
        // executor closes over a proposal id. Eligibility moving A→B must
        // never leave the live tool submitting the stale A.
        if (eligible && this.tools.has('apply_approved_plan') && this.boundProposalId !== proposalId) {
            this.unregisterDynamic();
        }

        // Register when we have an eligible approved proposal and it's absent.
        if (eligible && !this.tools.has('apply_approved_plan')) {
            const abort = new AbortController();
            this.dynamicAborts.set(proposalId!, abort);
            const tool = buildApplyApprovedPlanTool(
                this.projectId,
                proposalId!,
                () => this.markExecuted(),
            );
            this.boundProposalId = proposalId;
            this.track(tool, 'EXECUTE', true, abort);
        }

        // Unregister when no longer eligible.
        if (!eligible && this.tools.has('apply_approved_plan')) {
            this.unregisterDynamic();
        }

        return this.snapshot();
    }

    /** Mark execution as complete → unregister the dynamic tool. */
    markExecuted(): RegistrySnapshot {
        this.eligible = false;
        this.unregisterDynamic();
        return this.snapshot();
    }

    /** True if `name` is currently registered on the live WebMCP API. */
    has(name: string): boolean {
        return this.tools.has(name);
    }

    /** Names of currently registered tools (stable, deterministic). */
    registeredNames(): string[] {
        return [...this.tools.keys()].sort();
    }

    /**
     * Execute a currently-registered tool through its own executor. This is
     * exactly the path the WebMCP host would invoke: if the tool is not
     * registered right now (e.g. apply_approved_plan after execution), the
     * call is refused — demonstrating that authority is derived from the
     * live registry, not from a dangling reference.
     */
    async executeTool(name: string, args: Record<string, unknown> = {}): Promise<unknown> {
        const tracked = this.tools.get(name);
        if (!tracked) {
            return {
                ok: false,
                error: `Tool '${name}' is not registered in the current workspace context.`,
            };
        }
        if (tracked.abort?.signal.aborted) {
            return { ok: false, error: `Tool '${name}' was aborted (no longer eligible).` };
        }
        try {
            return await tracked.tool.execute(args);
        } catch (e) {
            return { ok: false, error: e instanceof Error ? e.message : String(e) };
        }
    }

    /** Subscribe to registry snapshots (e.g. diagnostics panel). */
    subscribe(fn: RegistryListener): () => void {
        this.listeners.add(fn);
        fn(this.snapshot());
        return () => this.listeners.delete(fn);
    }

    /** Tear down: unregister every tool (fires all abort signals). */
    dispose(): void {
        for (const tracked of this.tools.values()) {
            unregisterTool(tracked.tool.name, tracked.abort, this.context);
        }
        this.tools.clear();
        this.dynamicAborts.clear();
        this.eligible = false;
        this.emit();
    }

    /* ---------------------------------- internals ---------------------------------- */

    private baseTools(): ModelContextTool[] {
        return [
            ...workspaceTools(this.projectId),
            ...proposalTools(this.projectId),
            ...qaTools(this.projectId),
            // Sprint 2 — Creative Room: 4 READ + 4 PROPOSE. NO adoption tool:
            // creative direction is adopted exclusively through the human UI.
            ...creativeReadTools(this.projectId),
            ...creativeProposeTools(this.projectId),
            // Sprint 3 — context-aware culling: 2 READ + 1 ANALYZE. NO
            // photographer-decision tool: culling is finalized exclusively
            // through the human UI (photographer_decisions), never via WebMCP.
            ...cullingReadTools(this.projectId),
        ];
    }

    /**
     * The server-side authority for a base tool. Mirrors
     * App\Support\WebmcpToolCatalog — the catalogue remains authoritative;
     * this mirror only labels the diagnostics feed honestly.
     */
    private authorityFor(name: string): WebmcpAuthority {
        switch (name) {
            case 'propose_cull':
            case 'propose_retouch_plan':
            case 'reply_to_agent_conversation':
            case 'propose_concepts':
            case 'propose_concept_revision':
            case 'propose_concept_merge':
            case 'propose_creative_brief':
                return 'PROPOSE';
            // Sprint 3 — persists non-final photo_observations.
            case 'analyze_project_photos':
            // Sprint 4 — persists non-final qa_findings. NOT READ (it writes),
            // NOT PROPOSE (it proposes no creative option), never a decision.
            case 'run_consistency_review':
                return 'ANALYZE';
            default:
                return 'READ';
        }
    }

    private track(tool: ModelContextTool, authority: WebmcpAuthority, dynamic: boolean, abort: AbortController): void {
        registerTool(tool, abort.signal, this.context);
        this.tools.set(tool.name, {
            tool,
            authority,
            dynamic,
            abort,
            registeredAt: new Date().toISOString(),
        });
        this.emit();
    }

    private unregisterDynamic(): void {
        const tracked = this.tools.get('apply_approved_plan');
        if (!tracked) return;
        // Abort in-flight execution if any, then unregister from WebMCP.
        unregisterTool('apply_approved_plan', tracked.abort, this.context);
        this.tools.delete('apply_approved_plan');
        this.dynamicAborts.clear();
        this.boundProposalId = null;
        this.emit();
    }

    private snapshot(): RegistrySnapshot {
        return {
            registered: [...this.tools.values()]
                .map((t) => ({
                    name: t.tool.name,
                    authority: t.authority,
                    dynamic: t.dynamic,
                    registeredAt: t.registeredAt,
                }))
                .sort((a, b) => a.name.localeCompare(b.name)),
            webmcpAvailable: this.webmcpAvailable,
            usingFallback: this.usingFallback,
            eligibleForExecution: this.eligible,
        };
    }

    private emit(): void {
        const snap = this.snapshot();
        for (const fn of this.listeners) fn(snap);
    }
}
