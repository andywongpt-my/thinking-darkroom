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

        if (result.ok) onExecuted?.();

        return result;
    },
});
