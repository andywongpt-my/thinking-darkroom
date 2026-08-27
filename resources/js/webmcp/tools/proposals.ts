/**
 * PROPOSE-authority WebMCP tools: proposals can be created but never touch
 * creative state by themselves.
 */
import type { ModelContextTool } from '../tool-types';
import { webmcpApi } from '../api';

function photoId(args: Record<string, unknown>): number {
    return Number(args.photoId);
}

export const proposalTools = (projectId: number): ModelContextTool[] => [
    {
        name: 'propose_cull',
        description:
            'Creates a cull proposal with proposal_items. Does NOT change any actual photographer selection — the photographer must approve before execution. PROPOSE authority.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {
                items: {
                    type: 'array',
                    description: 'Photos to propose culling, with a rationale per item.',
                    items: {
                        type: 'object',
                        additionalProperties: false,
                        properties: {
                            photoId: { type: 'integer', description: 'Photo ID to cull.' },
                            rationale: {
                                type: 'string',
                                description: 'Why this photo should be culled.',
                            },
                        },
                        required: ['photoId'],
                    },
                },
                summary: {
                    type: 'string',
                    description: 'Optional summary of the cull proposal.',
                },
            },
            required: ['items'],
        },
        annotations: { readOnlyHint: false },
        execute: (args) =>
            webmcpApi.proposeCull(
                projectId,
                (args.items as { photoId: number; rationale?: string }[]).map((i) => ({
                    photo_id: photoId(i as unknown as Record<string, unknown>),
                    action: 'cull',
                    rationale: i.rationale,
                })),
                typeof args.summary === 'string' ? args.summary : undefined,
            ),
    },
    {
        name: 'propose_retouch_plan',
        description:
            'Creates a retouch proposal only. Does NOT apply any edits — the photographer must approve before execution. PROPOSE authority.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {
                items: {
                    type: 'array',
                    description: 'Retouch operations to propose.',
                    items: {
                        type: 'object',
                        additionalProperties: false,
                        properties: {
                            photoId: { type: 'integer' },
                            operation: {
                                type: 'string',
                                enum: ['exposure', 'white_balance', 'crop', 'spot_heal', 'tone_curve'],
                                description: 'Type of retouch operation.',
                            },
                            params: {
                                type: 'object',
                                description: 'Operation parameters.',
                            },
                            rationale: { type: 'string' },
                        },
                        required: ['photoId', 'operation'],
                    },
                },
                summary: { type: 'string' },
            },
            required: ['items'],
        },
        annotations: { readOnlyHint: false },
        execute: (args) =>
            webmcpApi.proposeRetouchPlan(
                projectId,
                (args.items as { photoId: number; operation: string; params?: Record<string, unknown>; rationale?: string }[]).map(
                    (i) => ({
                        photo_id: photoId(i as unknown as Record<string, unknown>),
                        action: i.operation,
                        params: i.params,
                        rationale: i.rationale,
                    }),
                ),
                typeof args.summary === 'string' ? args.summary : undefined,
            ),
    },
];
