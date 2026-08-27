/**
 * Sprint 2 — Creative Room WebMCP tools (mirrors App\Support\WebmcpToolCatalog).
 *
 * READ    (readOnlyHint: true) : get_brainstorm_context, get_creative_direction,
 *                                list_concepts, get_concept
 * PROPOSE (readOnlyHint: false): propose_concepts, propose_concept_revision,
 *                                propose_concept_merge, propose_creative_brief
 *
 * There is deliberately NO adoption / approval / final-direction tool here and
 * none may ever be added: adopting a creative direction is EXCLUSIVELY a
 * photographer action exercised through the Creative Room UI
 * (CreativeRoomReviewController → CreativeRoomService).
 */
import type { ModelContextTool } from '../tool-types';
import { webmcpApi } from '../api';
import type { ConceptInput } from '../api';
import { emitToolActivity } from '../events';

const MAX_CONCEPTS = 3;

const conceptInputSchema = {
    type: 'object' as const,
    additionalProperties: false as const,
    properties: {
        title: { type: 'string' as const, description: 'Short concept title.' },
        summary: { type: 'string' as const, description: 'One-paragraph summary of the creative idea.' },
        content: {
            type: 'object' as const,
            additionalProperties: false as const,
            description: 'Structured dimensions: mood, story/emotional_intent, composition, lighting, color, subject_direction, selection_priorities, retouch_philosophy, avoid.',
            properties: {
                mood: { type: 'array' as const, items: { type: 'string' as const } },
                story: { type: 'array' as const, items: { type: 'string' as const } },
                composition: { type: 'array' as const, items: { type: 'string' as const } },
                lighting: { type: 'array' as const, items: { type: 'string' as const } },
                color: { type: 'object' as const, description: 'Color treatment (palette, grading direction).' },
                subject_direction: { type: 'array' as const, items: { type: 'string' as const } },
                selection_priorities: { type: 'object' as const },
                retouch_philosophy: { type: 'string' as const },
                avoid: { type: 'array' as const, items: { type: 'string' as const } },
            },
        },
        items: {
            type: 'array' as const,
            description: 'Optional discrete readable traits attached to the concept.',
            items: {
                type: 'object' as const,
                additionalProperties: false as const,
                properties: {
                    dimension: { type: 'string' as const },
                    label: { type: 'string' as const },
                    value: { type: 'string' as const },
                    source: { type: 'string' as const, enum: ['agent', 'photographer'] },
                },
                required: ['dimension'],
            },
        },
    },
    required: ['title', 'content'],
};

function conceptInputFrom(args: Record<string, unknown>): ConceptInput {
    return args as unknown as ConceptInput;
}

export const creativeReadTools = (projectId: number): ModelContextTool[] => [
    {
        name: 'get_brainstorm_context',
        description:
            'Returns the current brainstorm session + freeform photographer input for the project, and the adopted creative direction if one exists. READ only.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {},
            required: [],
        },
        annotations: { readOnlyHint: true },
        execute: () => webmcpApi.getBrainstormContext(projectId),
    },
    {
        name: 'get_creative_direction',
        description:
            'Returns the ADOPTED creative direction for the project: the adopted concept plus its derived structured creative brief. Null when no direction is adopted. READ only.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {},
            required: [],
        },
        annotations: { readOnlyHint: true },
        execute: () => webmcpApi.getCreativeDirection(projectId),
    },
    {
        name: 'list_concepts',
        description:
            'Lists every creative concept for the project with status (proposed/exploring/rejected/merged/superseded/adopted) and lineage (parent + merge basis). READ only.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {},
            required: [],
        },
        annotations: { readOnlyHint: true },
        execute: () => webmcpApi.listConcepts(projectId),
    },
    {
        name: 'get_concept',
        description:
            'Returns a single creative concept with full structured content, items and lineage. READ only.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {
                conceptId: {
                    type: 'integer',
                    description: 'ID of the concept to retrieve.',
                    minimum: 1,
                },
            },
            required: ['conceptId'],
        },
        annotations: { readOnlyHint: true },
        execute: (args) => webmcpApi.getConcept(projectId, Number(args.conceptId)),
    },
];

export const creativeProposeTools = (projectId: number): ModelContextTool[] => [
    {
        name: 'propose_concepts',
        description:
            'Proposes up to 3 structured creative concepts from the brainstorm context. Concepts are created in PROPOSED status — they NEVER adopt a direction; adoption is ALWAYS the photographer\'s. PROPOSE authority.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {
                brainstormSessionId: {
                    type: 'integer',
                    description: 'Optional brainstorm session this proposal reasons from.',
                },
                concepts: {
                    type: 'array',
                    description: '1–3 structured concepts.',
                    minItems: 1,
                    maxItems: MAX_CONCEPTS,
                    items: conceptInputSchema,
                },
            },
            required: ['concepts'],
        },
        annotations: { readOnlyHint: false },
        execute: (args) => {
            const concepts = (args.concepts as Record<string, unknown>[]).slice(0, MAX_CONCEPTS);
            const result = webmcpApi.proposeConcepts(
                projectId,
                concepts.map((c) => conceptInputFrom(c)),
                typeof args.brainstormSessionId === 'number' ? args.brainstormSessionId : undefined,
            );
            // Notify the hosting page so new concept cards appear without a
            // manual refresh (covers BOTH the in-page registry and a real
            // WebMCP host — both call this executor).
            result.then((r) => emitToolActivity('propose_concepts', r.ok));
            return result;
        },
    },
    {
        name: 'propose_concept_revision',
        description:
            'Proposes a child/revision of an existing concept while preserving parent lineage. The parent concept is untouched. The child is created in PROPOSED status — adoption stays human-only. PROPOSE authority.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {
                conceptId: {
                    type: 'integer',
                    description: 'ID of the source (parent) concept to revise from.',
                    minimum: 1,
                },
                title: { type: 'string', description: 'Short title for the revised concept.' },
                summary: { type: 'string', description: 'One-paragraph summary.' },
                content: conceptInputSchema.properties.content,
                items: conceptInputSchema.properties.items,
            },
            required: ['conceptId', 'title', 'content'],
        },
        annotations: { readOnlyHint: false },
        execute: (args) => {
            const result = webmcpApi.proposeConceptRevision(projectId, Number(args.conceptId), {
                title: String(args.title),
                summary: typeof args.summary === 'string' ? args.summary : undefined,
                content: (args.content ?? {}) as Record<string, unknown>,
                items: args.items as ConceptInput['items'],
            });
            result.then((r) => emitToolActivity('propose_concept_revision', r.ok));
            return result;
        },
    },
    {
        name: 'propose_concept_merge',
        description:
            'Combines structured ideas from two or more concepts into a NEW proposed concept with visible lineage (both source concepts referenced). Sources are untouched. Adoption stays human-only. PROPOSE authority.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {
                sources: {
                    type: 'array',
                    description: '2+ source concepts to merge.',
                    minItems: 2,
                    items: {
                        type: 'object',
                        additionalProperties: false,
                        properties: {
                            conceptId: { type: 'integer', description: 'Source concept ID.' },
                            note: { type: 'string', description: 'What this source contributes.' },
                        },
                        required: ['conceptId'],
                    },
                },
                title: { type: 'string', description: 'Title for the merged concept.' },
                summary: { type: 'string' },
                content: conceptInputSchema.properties.content,
                items: conceptInputSchema.properties.items,
            },
            required: ['sources', 'title', 'content'],
        },
        annotations: { readOnlyHint: false },
        execute: (args) => {
            const sources = (args.sources as { conceptId: number; note?: string }[]).map((s) => ({
                concept_id: Number(s.conceptId),
                ...(s.note !== undefined ? { note: s.note } : {}),
            }));
            const result = webmcpApi.proposeConceptMerge(projectId, sources, {
                title: String(args.title),
                summary: typeof args.summary === 'string' ? args.summary : undefined,
                content: (args.content ?? {}) as Record<string, unknown>,
                items: args.items as ConceptInput['items'],
            });
            result.then((r) => emitToolActivity('propose_concept_merge', r.ok));
            return result;
        },
    },
    {
        name: 'propose_creative_brief',
        description:
            'Proposes a structured creative brief for the photographer to review. Persists a PROPOSAL only — it never adopts or activates the brief; the photographer adopts through the UI. PROPOSE authority.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {
                title: { type: 'string', description: 'Headline for the proposed brief.' },
                sourceConceptId: {
                    type: 'integer',
                    description: 'Optional concept this brief derives from.',
                },
                payload: {
                    type: 'object',
                    additionalProperties: false,
                    description: 'Structured brief: mood[], emotional_intent[], selection_priority{primary,secondary}, composition[], lighting[], color{}, subject_direction[], retouch{}, avoid[].',
                    properties: {
                        mood: { type: 'array', items: { type: 'string' } },
                        emotional_intent: { type: 'array', items: { type: 'string' } },
                        selection_priority: {
                            type: 'object',
                            additionalProperties: false,
                            properties: {
                                primary: { type: 'string' },
                                secondary: { type: 'string' },
                            },
                        },
                        composition: { type: 'array', items: { type: 'string' } },
                        lighting: { type: 'array', items: { type: 'string' } },
                        color: { type: 'object' },
                        subject_direction: { type: 'array', items: { type: 'string' } },
                        retouch: { type: 'object' },
                        avoid: { type: 'array', items: { type: 'string' } },
                    },
                },
            },
            required: ['title', 'payload'],
        },
        annotations: { readOnlyHint: false },
        execute: (args) =>
            webmcpApi.proposeCreativeBrief(
                projectId,
                String(args.title),
                (args.payload ?? {}) as Record<string, unknown>,
                typeof args.sourceConceptId === 'number' ? args.sourceConceptId : undefined,
            ),
    },
];
