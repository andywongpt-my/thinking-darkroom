/**
 * QA WebMCP tool: run_consistency_review. ANALYZE authority — the scan
 * PERSISTS qa_findings (so it is NOT read-only), but findings are non-final
 * analysis: it never modifies creative state or approves anything.
 */
import type { ModelContextTool } from '../tool-types';
import { webmcpApi } from '../api';

export const qaTools = (projectId: number): ModelContextTool[] => [
    {
        name: 'run_consistency_review',
        description:
            'Runs a deterministic consistency review over the selected set (observations + applied derivative adjustments + adopted Creative Brief) and PERSISTS qa_findings. ANALYZE authority — persists analysis, never creative decisions; severity is judged relative to the adopted brief.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {
                scope: {
                    type: 'string',
                    enum: ['selected', 'all', 'culled'],
                    description: 'Which subset of photos to review.',
                },
            },
            required: [],
        },
        annotations: { readOnlyHint: false },
        execute: (args) =>
            webmcpApi.runConsistencyReview(
                projectId,
                typeof args.scope === 'string' ? args.scope : 'selected',
            ),
    },
];
