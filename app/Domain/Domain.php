<?php

namespace App\Domain;

/**
 * Canonical domain vocabulary for the creative-authority model.
 *
 * These are plain string constants shared by migration defaults, models,
 * validation, and tests. Keeping them in one class prevents drift.
 */
final class Domain
{
    /* ---------------------------------- roles --------------------------------- */

    /** Photographer retaining final creative control. */
    public const ROLE_OWNER = 'owner';

    public const ROLE_PHOTOGRAPHER = 'photographer';

    /** Machine actor (AI agent) — never a creative authority on its own. */
    public const ROLE_AGENT = 'agent';

    public const ROLE_VIEWER = 'viewer';

    /* ----------------------------- proposal types ------------------------------ */

    /** Duplicate/blur/technical-reject culling proposal. */
    public const TYPE_CULL = 'cull';

    /** Single-photo retouch plan. */
    public const TYPE_RETOUCH = 'retouch';

    /** Multi-photo retouch plan (consistent look applied across a set). */
    public const TYPE_BATCH_RETOUCH = 'batch_retouch';

    /** Proposal that resolves previously-created QA findings. */
    public const TYPE_QA_RESOLUTION = 'qa_resolution';

    /* --------------------------------- states ---------------------------------- */

    public const STATE_DRAFT = 'draft';

    public const STATE_PENDING_REVIEW = 'pending_review';

    public const STATE_APPROVED = 'approved';

    /** Photographer changed the plan; a new pending proposal supersedes this one. */
    public const STATE_MODIFIED = 'modified';

    public const STATE_REJECTED = 'rejected';

    public const STATE_EXECUTED = 'executed';

    /** Proposal lifecycle: the ordered progression a proposal can pass through. */
    public const PROPOSAL_STATES = [
        self::STATE_DRAFT,
        self::STATE_PENDING_REVIEW,
        self::STATE_APPROVED,
        self::STATE_MODIFIED,
        self::STATE_REJECTED,
        self::STATE_EXECUTED,
    ];

    /* ---------------------------- creative authority ---------------------------- */

    /** Agent may act autonomously. */
    public const AUTHORITY_READ = 'READ';

    /** Agent may create a proposal but not make the final creative change. */
    public const AUTHORITY_PROPOSE = 'PROPOSE';

    /** Execution tool available only after photographer approval. */
    public const AUTHORITY_EXECUTE = 'EXECUTE';

    /* --------------------------- sprint 2: concepts --------------------------- */

    /** Concept is awaiting review/photographer attention. */
    public const CONCEPT_STATUS_PROPOSED = 'proposed';

    /** Photographer is actively exploring/reworking this concept. */
    public const CONCEPT_STATUS_EXPLORING = 'exploring';

    public const CONCEPT_STATUS_REJECTED = 'rejected';

    /** Concept built from combining two or more source concepts. */
    public const CONCEPT_STATUS_MERGED = 'merged';

    /** A formerly-adopted direction replaced by a newer adoption. Terminal. */
    public const CONCEPT_STATUS_SUPERSEDED = 'superseded';

    /** Photographer adopted this concept as the current creative direction. */
    public const CONCEPT_STATUS_ADOPTED = 'adopted';

    /** Ordered lifecycle of a creative concept. */
    public const CONCEPT_STATUSES = [
        self::CONCEPT_STATUS_PROPOSED,
        self::CONCEPT_STATUS_EXPLORING,
        self::CONCEPT_STATUS_REJECTED,
        self::CONCEPT_STATUS_MERGED,
        self::CONCEPT_STATUS_SUPERSEDED,
        self::CONCEPT_STATUS_ADOPTED,
    ];

    /** Agent tools that are NEVER allowed to exist (final-authority boundary). */
    public const FORBIDDEN_CREATIVE_DIRECTION_TOOLS = [
        'adopt_creative_direction',
        'approve_concept',
        'set_final_creative_direction',
        'force_creative_direction',
        'bypass_creative_review',
    ];

    /* ----------------------------- photo selection ------------------------------ */

    public const SELECTION_UNREVIEWED = 'unreviewed';

    public const SELECTION_SELECTED = 'selected';

    public const SELECTION_CULLED = 'culled';

    /* ------------------------------ retouch states ------------------------------ */

    public const RETOUCH_NONE = 'none';

    public const RETOUCH_PROPOSED = 'proposed';

    public const RETOUCH_APPROVED = 'approved';

    public const RETOUCH_APPLIED = 'applied';

    /* ---------------------------------- misc ----------------------------------- */

    public const QA_SEVERITIES = ['info', 'warning', 'error', 'critical'];

    public const QA_STATUSES = ['open', 'acknowledged', 'resolved'];

    /** Tools the agent is NEVER allowed to invoke (final-authority boundary). */
    public const FORBIDDEN_TOOL_ACTIONS = [
        'delete_photo',
        'delete_original',
        'bypass_approval',
        'final_client_delivery',
        'destroy',
    ];

    /** Results the agent tool audit trail records. */
    public const RESULT_COMPLETED = 'completed';

    public const RESULT_DENIED = 'denied';

    public const RESULT_WARNING = 'warning';

    public const RESULT_ERROR = 'error';
}
