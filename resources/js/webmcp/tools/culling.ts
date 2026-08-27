/**
 * Sprint 3 — context-aware culling agent tools.
 *
 * Two READ tools expose STRUCTURED OBSERVATIONS and RECOMMENDATIONS to the
 * agent; one ANALYZE tool derives + persists non-final observations.
 * They never change photographer selections — the photographer's decision
 * endpoint deliberately has NO tool wrapper (human authority).
 *
 * Provenance is explicit in every response:
 *   technical → pixel_analysis (deterministic GD statistics)
 *   creative  → demo_sidecar_annotation (human-authored demo labels)
 */
import type { ModelContextTool } from '../tool-types';
import { webmcpApi } from '../api';

export const cullingReadTools = (projectId: number): ModelContextTool[] => [
    {
        name: 'get_photo_analysis',
        description:
            'Returns one photo\'s structured observation — technical section from deterministic pixel analysis (sharpness, exposure, motion blur, highlight clipping), creative section from a human-authored demo sidecar annotation (expression, candidness, mood) — each section labeled with its provenance, plus the context-aware recommendation against the adopted Creative Brief. READ only; observations never change selections.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {
                photoId: {
                    type: 'integer',
                    description: 'ID of the photo to analyze.',
                    minimum: 1,
                },
            },
            required: ['photoId'],
        },
        annotations: { readOnlyHint: true },
        execute: (args) =>
            webmcpApi.getPhotoAnalysis(projectId, Number(args.photoId)).then((res) => res.data),
    },
    {
        name: 'get_culling_context',
        description:
            'Returns the project-wide culling picture: the adopted creative direction, per-photo observations and recommendations (recommendation, confidence, technical + creative rationale, tradeoff, influenced_by, similarity groups). READ only.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {},
            required: [],
        },
        annotations: { readOnlyHint: true },
        execute: () =>
            webmcpApi.getCullingContext(projectId).then((res) => res.data),
    },
    {
        name: 'analyze_project_photos',
        description:
            'Runs deterministic photo analysis over any photos not yet observed (idempotent — already-observed photos keep their stable evidence). Persists NON-FINAL photo_observations only (ANALYZE authority): never proposals, never selection changes — the photographer still makes every final call.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {},
            required: [],
        },
        // ANALYZE — not read-only: the run persists photo_observations rows.
        annotations: { readOnlyHint: false },
        execute: () =>
            webmcpApi.analyzeProjectPhotos(projectId).then((res) => res.data),
    },
];
