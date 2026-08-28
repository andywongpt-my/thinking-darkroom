/**
 * EXECUTE-authority WebMCP tool: apply_approved_plan.
 *
 * This is the ONLY tool with EXECUTE authority and it must NEVER be present
 * unless the current project holds an approved, unexecuted proposal. It is
 * registered dynamically by the registry (not imported into the base set) and
 * unregistered — via its AbortController — the moment it is no longer valid.
 */
import type { ModelContextTool } from '../tool-types';
import { webmcpApi } from '../api';

export const buildApplyApprovedPlanTool = (
    projectId: number,
    proposalId: number,
    onExecuted?: () => void,
): ModelContextTool => ({
    name: 'apply_approved_plan',
    description:
        'Executes an approved, unexecuted proposal. Available ONLY because the photographer has approved a plan; it is removed as soon as the plan is executed or no longer eligible. EXECUTE authority.',
    inputSchema: {
        type: 'object',
        additionalProperties: false,
        properties: {},
        required: [],
    },
    annotations: { readOnlyHint: false },
    execute: async () => {
        const result = await webmcpApi.applyApprovedPlan(projectId, proposalId);

        if (result.ok) {
            // Unregister AFTER the host has settled this very tool call: the
            // lifecycle teardown (AbortController.abort + host unregisterTool)
            // fires the registration signal, and the Chrome WebMCP host treats
            // an aborted registration as an aborted in-flight call — it then
            // discards our result and throws to the caller even though the
            // HTTP execution fully succeeded. A microtask still resolves
            // before the host settles the promise, so yield a full macrotask
            // first (live-certification finding, Sprint 4; verified: with the
            // abort signal suppressed the same call resolves the ToolResult).
            setTimeout(() => onExecuted?.(), 0);
        }

        return result;
    },
});
