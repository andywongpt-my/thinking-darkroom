/**
 * Thin typed client for the WebMCP API surface (same-origin, Sanctum
 * statefulApi session + CSRF). These shapes mirror the controller responses.
 */
import axios from 'axios';

export interface PhotoSummary {
    id: number;
    filename: string;
    url: string | null;
    mime: string | null;
    width: number | null;
    height: number | null;
    size_bytes: number | null;
    selection_state: string;
    retouch_state: string;
}

export interface PhotoDetail extends PhotoSummary {
    original_name: string | null;
    camera_make: string | null;
    camera_model: string | null;
    lens: string | null;
    iso: number | null;
    aperture: string | null;
    shutter_speed: string | null;
    focal_length: string | null;
    captured_at: string | null;
}

export interface Brief {
    id: number;
    client: string | null;
    shoot_date: string | null;
    location: string | null;
    creative_direction: string | null;
    tonality_notes: string | null;
    deliverables: string | null;
    status?: string;
}

export interface WorkspaceContext {
    project: {
        id: number;
        name: string;
        status: string;
        description: string | null;
    };
    brief: Brief | null;
    counts: {
        total: number;
        selected: number;
        culled: number;
        unreviewed: number;
    };
    proposals: {
        pending: number;
        approved_unexecuted: number;
    };
    qa: { open: number };
    webmcp_available: boolean;
    generated_at: string;
}

export interface DecisionEntry {
    id: number;
    proposal_id: number | null;
    proposal_type: string | null;
    proposal_status: string | null;
    photographer: string | null;
    decision: string;
    note: string | null;
    modifications: Record<string, unknown> | null;
    decided_at: string;
}

export interface ProposalMeta {
    id: number;
    type: string;
    status: string;
    summary: string | null;
    items_count: number;
    reviewed_at: string | null;
    executed_at: string | null;
}

export interface DecisionHistory {
    project_id: number;
    decisions: DecisionEntry[];
    proposals: ProposalMeta[];
}

export interface ProposalItemInput {
    photo_id: number;
    action: string;
    rationale?: string;
    params?: Record<string, unknown>;
}

export interface ProposalItemPayload {
    id: number;
    photo_id: number;
    kind: string;
    action: string;
    rationale: string | null;
    params: Record<string, unknown> | null;
    status: string;
    result?: Record<string, unknown> | null;
}

export interface ProposalPayload {
    proposal: {
        id: number;
        project_id: number;
        type: string;
        status: string;
        summary: string | null;
        created_by: number | null;
        created_at: string;
        reviewed_at: string | null;
        executed_at: string | null;
        items: ProposalItemPayload[];
    };
}

/* ------------------------- Sprint 2: Creative Room ------------------------- */

export interface ConceptItemPayload {
    id?: number;
    dimension: string;
    label: string | null;
    value: string | null;
    source: string;
}

export interface ConceptPayload {
    id: number;
    project_id: number;
    brainstorm_session_id: number | null;
    parent_concept_id: number | null;
    title: string;
    summary: string | null;
    content: Record<string, unknown>;
    status: string;
    created_by: number | null;
    creator_name?: string | null;
    creator_is_agent?: boolean;
    lineage_basis: { concept_id: number; title: string; note?: string | null }[] | null;
    adopted_at: string | null;
    created_at?: string | null;
    items: ConceptItemPayload[];
}

export interface BrainstormContext {
    project_id: number;
    brainstorm: {
        id: number;
        input: string;
        status: string;
        photographer?: string | null;
        created_at: string;
    } | null;
    creative_direction: StructuredIntent | null;
}

export interface StructuredIntent {
    project_id: number;
    has_direction: boolean;
    adopted_concept: Record<string, unknown> | null;
    brief: Record<string, unknown> | null;
    intent: Record<string, unknown>;
}

export interface ConceptInput {
    title: string;
    summary?: string;
    content: Record<string, unknown>;
    items?: { dimension: string; label?: string; value?: string; source?: string }[];
    [key: string]: unknown;
}

export interface BriefProposalPayload {
    id: number;
    status: string;
    creative_direction: string;
    tonality_notes: string | null;
    deliverables: string | null;
    payload: Record<string, unknown>;
}

export interface QaFindingPayload {
    id: number;
    severity: string;
    category: string;
    message: string;
    photo_id: number | null;
}

export interface QaReviewResult {
    project_id: number;
    scope: string;
    photos_checked: number;
    created_findings: QaFindingPayload[];
}

export interface DiagnosticsResponse {
    project_id: number;
    webmcp_available: boolean;
    eligible_approval: boolean;
    tools: {
        name: string;
        authority: string;
        method: string;
        path: string;
        read_only: boolean;
        description: string;
        dynamic: boolean;
    }[];
}

/** Synthesised agent result — what the agent (or the UI echoing the agent)
 *  would observe after a tool call. */
export interface ToolResult<T> {
    ok: boolean;
    status: number;
    data: T | null;
    error: string | null;
}

const client = axios.create({});

const projectApiPath = (projectId: number, suffix: string): string =>
    `/api/projects/${projectId}${suffix}`;

const projectApiPaths = {
    workspaceContext: (projectId: number) =>
        projectApiPath(projectId, '/workspace/context'),
    photos: (projectId: number) => projectApiPath(projectId, '/photos'),
    photo: (projectId: number, photoId: number) =>
        projectApiPath(projectId, `/photos/${photoId}`),
    brief: (projectId: number) => projectApiPath(projectId, '/brief'),
    decisions: (projectId: number) => projectApiPath(projectId, '/decisions'),
    cullProposal: (projectId: number) =>
        projectApiPath(projectId, '/proposals/cull'),
    retouchPlanProposal: (projectId: number) =>
        projectApiPath(projectId, '/proposals/retouch-plan'),
    consistencyReview: (projectId: number) =>
        projectApiPath(projectId, '/qa/review'),
    executeProposal: (projectId: number, proposalId: number) =>
        projectApiPath(projectId, `/proposals/${proposalId}/execute`),
};

async function get<T>(url: string): Promise<ToolResult<T>> {
    try {
        const resp = await client.get<T>(url);
        return { ok: true, status: resp.status, data: resp.data, error: null };
    } catch (e) {
        if (axios.isAxiosError(e)) {
            return {
                ok: false,
                status: e.response?.status ?? 0,
                data: null,
                error: e.response?.data?.error ?? e.message,
            };
        }
        return { ok: false, status: 0, data: null, error: String(e) };
    }
}

async function post<T>(url: string, body?: Record<string, unknown>): Promise<ToolResult<T>> {
    try {
        const resp = await client.post<T>(url, body ?? {});
        return { ok: true, status: resp.status, data: resp.data, error: null };
    } catch (e) {
        if (axios.isAxiosError(e)) {
            return {
                ok: false,
                status: e.response?.status ?? 0,
                data: null,
                error: e.response?.data?.error ?? e.message,
            };
        }
        return { ok: false, status: 0, data: null, error: String(e) };
    }
}

export const webmcpApi = {
    getWorkspaceContext(projectId: number) {
        return get<WorkspaceContext>(projectApiPaths.workspaceContext(projectId));
    },

    listProjectPhotos(projectId: number) {
        return get<{ project_id: number; count: number; photos: PhotoSummary[] }>(
            projectApiPaths.photos(projectId),
        );
    },

    inspectPhoto(projectId: number, photoId: number) {
        return get<{ photo: PhotoDetail }>(
            projectApiPaths.photo(projectId, photoId),
        );
    },

    getCreativeBrief(projectId: number) {
        return get<{ project_id: number; brief: Brief | null }>(
            projectApiPaths.brief(projectId),
        );
    },

    getDecisionHistory(projectId: number) {
        return get<DecisionHistory>(projectApiPaths.decisions(projectId));
    },

    proposeCull(
        projectId: number,
        items: ProposalItemInput[],
        summary?: string,
    ) {
        return post<ProposalPayload>(
            projectApiPaths.cullProposal(projectId),
            { summary: summary ?? null, items },
        );
    },

    proposeRetouchPlan(
        projectId: number,
        items: ProposalItemInput[],
        summary?: string,
    ) {
        return post<ProposalPayload>(
            projectApiPaths.retouchPlanProposal(projectId),
            { summary: summary ?? null, items },
        );
    },

    runConsistencyReview(projectId: number, scope = 'selected') {
        return post<QaReviewResult>(
            projectApiPaths.consistencyReview(projectId),
            { scope },
        );
    },

    applyApprovedPlan(projectId: number, proposalId: number) {
        return post<ProposalPayload>(
            projectApiPaths.executeProposal(projectId, proposalId),
            {},
        );
    },

    /* ----------------------- Sprint 2: Creative Room ----------------------- */

    getBrainstormContext(projectId: number) {
        return get<BrainstormContext>(
            projectApiPath(projectId, '/creative/brainstorm'),
        );
    },

    getCreativeDirection(projectId: number) {
        return get<StructuredIntent>(
            projectApiPath(projectId, '/creative/direction'),
        );
    },

    listConcepts(projectId: number) {
        return get<{ project_id: number; concepts: ConceptPayload[] }>(
            projectApiPath(projectId, '/creative/concepts'),
        );
    },

    getConcept(projectId: number, conceptId: number) {
        return get<{ project_id: number; concept: ConceptPayload }>(
            projectApiPath(projectId, `/creative/concepts/${conceptId}`),
        );
    },

    proposeConcepts(projectId: number, concepts: ConceptInput[], brainstormSessionId?: number) {
        return post<{ project_id: number; concepts: ConceptPayload[] }>(
            projectApiPath(projectId, '/creative/concepts'),
            {
                concepts,
                ...(brainstormSessionId ? { brainstorm_session_id: brainstormSessionId } : {}),
            },
        );
    },

    proposeConceptRevision(projectId: number, conceptId: number, input: ConceptInput) {
        return post<{ project_id: number; concept: ConceptPayload }>(
            projectApiPath(projectId, `/creative/concepts/${conceptId}/revise`),
            input,
        );
    },

    proposeConceptMerge(
        projectId: number,
        sources: { concept_id: number; note?: string }[],
        input: ConceptInput,
    ) {
        return post<{ project_id: number; concept: ConceptPayload }>(
            projectApiPath(projectId, '/creative/merge'),
            { sources, ...input },
        );
    },

    proposeCreativeBrief(
        projectId: number,
        title: string,
        payload: Record<string, unknown>,
        sourceConceptId?: number,
    ) {
        return post<{ project_id: number; brief_proposal: BriefProposalPayload; adopted: boolean }>(
            projectApiPath(projectId, '/creative/brief-proposal'),
            {
                title,
                payload,
                ...(sourceConceptId ? { source_concept_id: sourceConceptId } : {}),
            },
        );
    },

    /* -------------------- Sprint 2: HUMAN-ONLY UI actions ------------------- */
    /* These deliberately have NO WebMCP tool wrapper — adoption/rejection is  */
    /* exclusively a photographer action exercised through the UI.             */

    openBrainstorm(projectId: number, input: string) {
        return post<{ project_id: number; brainstorm: { id: number; input: string; status: string; created_at: string } }>(
            `/projects/${projectId}/creative/brainstorm`,
            { input },
        );
    },

    exploreConcept(projectId: number, conceptId: number) {
        return post<{ concept: ConceptPayload }>(
            `/projects/${projectId}/creative/concepts/${conceptId}/explore`,
        );
    },

    rejectConcept(projectId: number, conceptId: number, note?: string) {
        return post<{ concept: ConceptPayload }>(
            `/projects/${projectId}/creative/concepts/${conceptId}/reject`,
            note ? { note } : {},
        );
    },

    adoptConcept(projectId: number, conceptId: number, note?: string) {
        return post<{ concept: ConceptPayload }>(
            `/projects/${projectId}/creative/concepts/${conceptId}/adopt`,
            note ? { note } : {},
        );
    },

    diagnostics(projectId: number) {
        return get<DiagnosticsResponse>(
            `/webmcp-diagnostics/projects/${projectId}/tools`,
        );
    },
};
