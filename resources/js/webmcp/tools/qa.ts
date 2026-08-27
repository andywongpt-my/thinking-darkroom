/**
 * QA WebMCP tool: run_consistency_review. PROPOSE authority (creates findings,
 * never modifies creative state).
 */
import type { ModelContextTool } from '../tool-types';
import { webmcpApi } from '../api';

export const qaTools = (projectId: number): ModelContextTool[] => [
    {
        name: 'run_consistency_review',
        description:
            'Runs a consistency review over the project photos (exposure/white-balance/metadata focus) and creates QA findings. PROPOSE authority — creates findings only, never modifies creative state.',
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
