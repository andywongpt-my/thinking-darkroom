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

export interface AgentPresenceEntry {
    id: number;
    name: string;
    status: 'online' | 'offline';
    last_seen_at: string | null;
}

export interface AgentPresence {
    project_id: number;
    online: boolean;
    agents: AgentPresenceEntry[];
    checked_at: string;
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

/* ----------------------- Sprint 3: context-aware culling ----------------------- */

export interface TechnicalAssessment {
    assessment: string;
    confidence: number;
}

export interface PhotoObservationPayload {
    photo_id: number;
    technical: {
        sharpness: TechnicalAssessment;
        exposure: TechnicalAssessment;
        motion_blur: TechnicalAssessment;
        highlight_clipping: TechnicalAssessment;
        eyes_open: TechnicalAssessment | null;
    };
    creative: {
        expression: string;
        candidness: string;
        environmental_storytelling: string;
        mood: string[];
        compositional_fit: string;
        emotion_strength: string;
    };
    provider: string;
    provenance: string;
    similarity_group: string | null;
    technical_provenance: string;
    creative_provenance: string;
}

export interface CullingRecommendation {
    photo_id: number;
    recommendation: 'strong_keep' | 'keep' | 'review' | 'reject_candidate';
    confidence: number;
    technical_rationale: string;
    creative_rationale: string;
    tradeoff: string;
    influenced_by: string[];
}

export interface CullingRecommendationEntry extends CullingRecommendation {
    photo: {
        id: number;
        filename: string;
        url: string | null;
        selection_state: string;
        original_name: string | null;
    };
    similarity_group: string | null;
    similarity_group_size: number;
}

export interface CullingContext {
    project_id: number;
    has_direction: boolean;
    provider: string;
    provenance: string;
    context: {
        photos_observed: number;
        duplicate_groups: { photo_ids: number[]; count: number }[];
        adopted_concept: string | null;
        selection_priority: unknown;
    };
    recommendations: CullingRecommendationEntry[];
}

export interface PhotoAnalysisResponse {
    project_id: number;
    photo: PhotoSummary;
    observation: PhotoObservationPayload;
    recommendation: CullingRecommendation;
}

export interface AnalyzeProjectResponse {
    project_id: number;
    provider: string;
    newly_analyzed: number;
    refreshed_observations: number;
    total_observed: number;
    observations: PhotoObservationPayload[];
}

export interface PhotographerDecisionPayload {
    decision: {
        id: number;
        project_id: number;
        photo_id: number;
        decision: 'keep' | 'review' | 'reject';
        note: string | null;
        override: boolean;
        photographer: { id: number; name: string };
        decided_at: string | null;
    };
    photo: {
        id: number;
        selection_state: string;
    };
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
    presence: (projectId: number) => projectApiPath(projectId, '/presence'),
    presenceHeartbeat: (projectId: number) => projectApiPath(projectId, '/presence/heartbeat'),
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
    /* Sprint 3: context-aware culling */
    photoAnalysis: (projectId: number, photoId: number) =>
        projectApiPath(projectId, `/culling/photos/${photoId}/analysis`),
    cullingContext: (projectId: number) =>
        projectApiPath(projectId, '/culling/context'),
    cullingAnalyze: (projectId: number) =>
        projectApiPath(projectId, '/culling/analyze'),
    photographerDecide: (projectId: number, photoId: number) =>
        `/projects/${projectId}/culling/photos/${photoId}/decide`,
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

    getAgentPresence(projectId: number) {
        return get<AgentPresence>(projectApiPaths.presence(projectId));
    },

    heartbeatAgentPresence(projectId: number) {
        return post<AgentPresence>(projectApiPaths.presenceHeartbeat(projectId), {});
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

    /* ----------------------- Sprint 3: context-aware culling ----------------------- */

    getPhotoAnalysis(projectId: number, photoId: number) {
        return get<PhotoAnalysisResponse>(
            projectApiPaths.photoAnalysis(projectId, photoId),
        );
    },

    getCullingContext(projectId: number) {
        return get<CullingContext>(projectApiPaths.cullingContext(projectId));
    },

    analyzeProjectPhotos(projectId: number) {
        return post<AnalyzeProjectResponse>(
            projectApiPaths.cullingAnalyze(projectId),
            {},
        );
    },

    /* -------------------- Sprint 3: HUMAN-ONLY UI actions ------------------- */
    /* Photographer culling decisions/overrides. Deliberately NO WebMCP tool   */
    /* wrapper — keep/review/reject/override is exclusively a photographer     */
    /* action exercised through the UI.                                        */

    photographerCullingDecide(
        projectId: number,
        photoId: number,
        decision: 'keep' | 'review' | 'reject',
        note?: string,
        override?: boolean,
    ) {
        return post<PhotographerDecisionPayload>(
            projectApiPaths.photographerDecide(projectId, photoId),
            {
                decision,
                ...(note ? { note } : {}),
                ...(override !== undefined ? { override } : {}),
            },
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
