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

    diagnostics(projectId: number) {
        return get<DiagnosticsResponse>(
            `/webmcp-diagnostics/projects/${projectId}/tools`,
        );
    },
};
