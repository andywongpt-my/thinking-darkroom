<?php

namespace App\Support;

use App\Domain\Domain;

/**
 * Server-side catalog of WebMCP tools. This is the authoritative list of what
 * agent-origin calls are permitted to do — the browser registry mirrors it for
 * `document.modelContext`, but authority is enforced here regardless of what
 * the browser does.
 *
 * Note there is deliberately NO entry for approve/reject/modify, and no entry
 * for any destructive/final-authority action: those simply do not exist as
 * agent tools.
 */
final class WebmcpToolCatalog
{
    private const PROJECT_PLACEHOLDER = '__WEBMCP_PROJECT__';

    private const PHOTO_PLACEHOLDER = '__WEBMCP_PHOTO__';

    private const PROPOSAL_PLACEHOLDER = '__WEBMCP_PROPOSAL__';

    /**
     * Generate a route URI from Laravel's named route, while preserving
     * placeholders for the browser-side tool catalogue.
     *
     * @param array<string, string> $parameters
     */
    private static function routePath(string $routeName, array $parameters): string
    {
        $path = route($routeName, $parameters, false);

        foreach ($parameters as $parameter => $value) {
            $path = str_replace((string) $value, '{'.$parameter.'}', $path);
        }

        return '/'.ltrim($path, '/');
    }

    /**
     * @return array<string, array{
     *     name: string,
     *     authority: string,
     *     method: string,
     *     path: string,
     *     read_only: bool,
     *     description: string,
     *     dynamic: bool
     * }>
     */
    public static function all(): array
    {
        return [
            'get_workspace_context' => [
                'name' => 'get_workspace_context',
                'authority' => Domain::AUTHORITY_READ,
                'method' => 'GET',
                'path' => self::routePath('api.webmcp.context', [
                    'project' => self::PROJECT_PLACEHOLDER,
                ]),
                'read_only' => true,
                'description' => 'Returns the current workspace snapshot (project, brief, photo counts, status).',
                'dynamic' => false,
            ],
            'list_project_photos' => [
                'name' => 'list_project_photos',
                'authority' => Domain::AUTHORITY_READ,
                'method' => 'GET',
                'path' => self::routePath('api.webmcp.photos.index', [
                    'project' => self::PROJECT_PLACEHOLDER,
                ]),
                'read_only' => true,
                'description' => 'Lists photos in the project with selection and retouch state.',
                'dynamic' => false,
            ],
            'inspect_photo' => [
                'name' => 'inspect_photo',
                'authority' => Domain::AUTHORITY_READ,
                'method' => 'GET',
                'path' => self::routePath('api.webmcp.photos.show', [
                    'project' => self::PROJECT_PLACEHOLDER,
                    'photo' => self::PHOTO_PLACEHOLDER,
                ]),
                'read_only' => true,
                'description' => 'Returns detailed metadata and state for a single photo.',
                'dynamic' => false,
            ],
            'get_creative_brief' => [
                'name' => 'get_creative_brief',
                'authority' => Domain::AUTHORITY_READ,
                'method' => 'GET',
                'path' => self::routePath('api.webmcp.brief.show', [
                    'project' => self::PROJECT_PLACEHOLDER,
                ]),
                'read_only' => true,
                'description' => 'Returns the creative brief for the project.',
                'dynamic' => false,
            ],
            'get_decision_history' => [
                'name' => 'get_decision_history',
                'authority' => Domain::AUTHORITY_READ,
                'method' => 'GET',
                'path' => self::routePath('api.webmcp.decisions.index', [
                    'project' => self::PROJECT_PLACEHOLDER,
                ]),
                'read_only' => true,
                'description' => 'Returns the photographer decision history and proposal states for the project.',
                'dynamic' => false,
            ],
            // Sprint 2 — Creative Room READ tools.
            'get_brainstorm_context' => [
                'name' => 'get_brainstorm_context',
                'authority' => Domain::AUTHORITY_READ,
                'method' => 'GET',
                'path' => self::routePath('api.webmcp.creative.brainstorm', [
                    'project' => self::PROJECT_PLACEHOLDER,
                ]),
                'read_only' => true,
                'description' => 'Returns the current brainstorm session + input for the project, and the adopted creative direction if one exists. READ only.',
                'dynamic' => false,
            ],
            'get_creative_direction' => [
                'name' => 'get_creative_direction',
                'authority' => Domain::AUTHORITY_READ,
                'method' => 'GET',
                'path' => self::routePath('api.webmcp.creative.direction', [
                    'project' => self::PROJECT_PLACEHOLDER,
                ]),
                'read_only' => true,
                'description' => 'Returns the ADOPTED creative direction (concept + derived structured creative brief) for the project. Null when no direction is adopted. READ only.',
                'dynamic' => false,
            ],
            'list_concepts' => [
                'name' => 'list_concepts',
                'authority' => Domain::AUTHORITY_READ,
                'method' => 'GET',
                'path' => self::routePath('api.webmcp.creative.concepts', [
                    'project' => self::PROJECT_PLACEHOLDER,
                ]),
                'read_only' => true,
                'description' => 'Lists every creative concept for the project with status and lineage. READ only.',
                'dynamic' => false,
            ],
            'get_concept' => [
                'name' => 'get_concept',
                'authority' => Domain::AUTHORITY_READ,
                'method' => 'GET',
                'path' => self::routePath('api.webmcp.creative.concepts.show', [
                    'project' => self::PROJECT_PLACEHOLDER,
                    'concept' => '__WEBMCP_CONCEPT__',
                ]),
                'read_only' => true,
                'description' => 'Returns a single creative concept with full structured content and lineage. READ only.',
                'dynamic' => false,
            ],
            'propose_concepts' => [
                'name' => 'propose_concepts',
                'authority' => Domain::AUTHORITY_PROPOSE,
                'method' => 'POST',
                'path' => self::routePath('api.webmcp.creative.concepts', [
                    'project' => self::PROJECT_PLACEHOLDER,
                ]),
                'read_only' => false,
                'description' => 'Proposes up to 3 structured creative concepts from a brainstorm. Concepts are created in PROPOSED status — adoption is ALWAYS the photographer\'s. PROPOSE authority.',
                'dynamic' => false,
            ],
            'propose_concept_revision' => [
                'name' => 'propose_concept_revision',
                'authority' => Domain::AUTHORITY_PROPOSE,
                'method' => 'POST',
                'path' => self::routePath('api.webmcp.creative.concepts.revise', [
                    'project' => self::PROJECT_PLACEHOLDER,
                    'concept' => '__WEBMCP_CONCEPT__',
                ]),
                'read_only' => false,
                'description' => 'Proposes a child/revision of an existing concept while preserving lineage. The parent is untouched. PROPOSE authority.',
                'dynamic' => false,
            ],
            'propose_concept_merge' => [
                'name' => 'propose_concept_merge',
                'authority' => Domain::AUTHORITY_PROPOSE,
                'method' => 'POST',
                'path' => self::routePath('api.webmcp.creative.merge', [
                    'project' => self::PROJECT_PLACEHOLDER,
                ]),
                'read_only' => false,
                'description' => 'Combines structured ideas from two or more concepts into a new proposed concept with visible lineage. PROPOSE authority.',
                'dynamic' => false,
            ],
            'propose_creative_brief' => [
                'name' => 'propose_creative_brief',
                'authority' => Domain::AUTHORITY_PROPOSE,
                'method' => 'POST',
                'path' => self::routePath('api.webmcp.creative.brief-proposal', [
                    'project' => self::PROJECT_PLACEHOLDER,
                ]),
                'read_only' => false,
                'description' => 'Proposes a structured creative brief for the photographer to review. This persists a PROPOSAL only — it never adopts or activates the brief. The photographer adopts through the UI. PROPOSE authority.',
                'dynamic' => false,
            ],
            'propose_cull' => [
                'name' => 'propose_cull',
                'authority' => Domain::AUTHORITY_PROPOSE,
                'method' => 'POST',
                'path' => self::routePath('api.webmcp.proposals.cull', [
                    'project' => self::PROJECT_PLACEHOLDER,
                ]),
                'read_only' => false,
                'description' => 'Creates a cull proposal with proposal_items. Does NOT change photographer selections.',
                'dynamic' => false,
            ],
            'propose_retouch_plan' => [
                'name' => 'propose_retouch_plan',
                'authority' => Domain::AUTHORITY_PROPOSE,
                'method' => 'POST',
                'path' => self::routePath('api.webmcp.proposals.retouch', [
                    'project' => self::PROJECT_PLACEHOLDER,
                ]),
                'read_only' => false,
                'description' => 'Creates a retouch proposal only. Does NOT apply edits.',
                'dynamic' => false,
            ],
            'run_consistency_review' => [
                'name' => 'run_consistency_review',
                'authority' => Domain::AUTHORITY_PROPOSE,
                'method' => 'POST',
                'path' => self::routePath('api.webmcp.qa.review', [
                    'project' => self::PROJECT_PLACEHOLDER,
                ]),
                'read_only' => false,
                'description' => 'Runs a consistency review and creates qa_findings for the project.',
                'dynamic' => false,
            ],
            'apply_approved_plan' => [
                'name' => 'apply_approved_plan',
                'authority' => Domain::AUTHORITY_EXECUTE,
                'method' => 'POST',
                'path' => self::routePath('api.webmcp.proposals.execute', [
                    'project' => self::PROJECT_PLACEHOLDER,
                    'proposal' => self::PROPOSAL_PLACEHOLDER,
                ]),
                'read_only' => false,
                'description' => 'Executes an approved, unexecuted proposal. Only registered after photographer approval.',
                'dynamic' => true,
            ],
        ];
    }

    public static function find(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    /**
     * Tools a project currently exposes: all base tools plus the dynamic
     * execution tool when an eligible approved proposal exists.
     */
    public static function availableFor(\App\Models\Project $project): array
    {
        $tools = array_values(array_filter(
            self::all(),
            fn (array $t) => ! $t['dynamic'],
        ));

        if ($project->hasEligibleExecutableProposal()) {
            $tools[] = self::all()['apply_approved_plan'];
        }

        return $tools;
    }
}
