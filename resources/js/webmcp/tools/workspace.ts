/**
 * READ-only WebMCP tools: workspace + photos + brief + decision history.
 * Every tool carries readOnlyHint:true in its annotations.
 */
import type { ModelContextTool } from '../tool-types';
import { webmcpApi } from '../api';

function projectId(args: Record<string, unknown>): number {
    return Number(args.projectId);
}

export const workspaceTools = (projectId: number): ModelContextTool[] => [
    {
        name: 'get_workspace_context',
        description:
            'Returns the current workspace snapshot for the project: project metadata, creative brief, photo counts by selection state, pending/approved proposal counts and open QA findings. READ only.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {},
            required: [],
        },
        annotations: { readOnlyHint: true },
        execute: () => webmcpApi.getWorkspaceContext(projectId),
    },
    {
        name: 'list_project_photos',
        description:
            'Lists every photo in the project with selection and retouch state. READ only.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {
                statusFilter: {
                    type: 'string',
                    enum: ['all', 'unreviewed', 'selected', 'culled'],
                    description: 'Optional filter by selection state.',
                },
            },
            required: [],
        },
        annotations: { readOnlyHint: true },
        execute: async (args) => {
            const res = await webmcpApi.listProjectPhotos(projectId);
            if (args.statusFilter && args.statusFilter !== 'all' && res.ok && res.data) {
                res.data.photos = res.data.photos.filter(
                    (p) => p.selection_state === args.statusFilter,
                );
            }
            return res.data;
        },
    },
    {
        name: 'inspect_photo',
        description:
            'Returns detailed metadata and state for a single photo belonging to the project. READ only.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {
                photoId: {
                    type: 'integer',
                    description: 'ID of the photo to inspect.',
                    minimum: 1,
                },
            },
            required: ['photoId'],
        },
        annotations: { readOnlyHint: true },
        execute: (args) =>
            webmcpApi.inspectPhoto(projectId, Number(args.photoId)),
    },
    {
        name: 'get_creative_brief',
        description:
            'Returns the creative brief for the project (client, shoot date, location, creative direction, tonality and deliverables). READ only.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {},
            required: [],
        },
        annotations: { readOnlyHint: true },
        execute: () => webmcpApi.getCreativeBrief(projectId),
    },
    {
        name: 'get_decision_history',
        description:
            'Returns the photographer decision history and proposal states for the project. READ only.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {},
            required: [],
        },
        annotations: { readOnlyHint: true },
        execute: () => webmcpApi.getDecisionHistory(projectId),
    },
];
