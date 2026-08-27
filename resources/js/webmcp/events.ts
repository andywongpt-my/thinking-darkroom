/**
 * WebMCP tool-activity events — the minimal bridge that lets the pages
 * hosting the registry react to successful PROPOSE mutations initiated
 * through WebMCP (either by the in-page registry or by a real WebMCP host
 * calling the same tool executor).
 *
 * Deliberately NOT websockets, polling or a store: a plain event on a shared
 * module-level EventTarget. The Creative Room page listens for concept
 * mutations and refreshes its concept list, so a photographer sees new agent
 * proposals appear without a manual browser refresh.
 *
 * EventTarget exists as a global class in every supported runtime (browsers
 * and Node ≥ 15), so the bridge behaves identically in the app and in tests.
 *
 * Scope: same-page UI freshness only. The server remains the sole authority
 * for state; this event carries nothing but a tool name.
 */

export const WEBMCP_TOOL_ACTIVITY_EVENT = 'webmcp:tool-activity';

/** Tool names whose successful execution mutates the Creative Room concept list. */
const CONCEPT_MUTATING_TOOLS = new Set([
    'propose_concepts',
    'propose_concept_revision',
    'propose_concept_merge',
]);

export interface WebmcpToolActivityDetail {
    tool: string;
    ok: boolean;
    at: string;
}

/** Shared bus for all WebMCP tool-activity events. */
const bus: EventTarget = new EventTarget();

/** True when this runtime can dispatch/listen on an EventTarget. */
function hasEventTarget(): boolean {
    return (
        typeof EventTarget === 'function' &&
        typeof CustomEvent === 'function' &&
        typeof bus.dispatchEvent === 'function' &&
        typeof bus.addEventListener === 'function'
    );
}

/** Fire-and-forget: announce a completed WebMCP tool execution. */
export function emitToolActivity(tool: string, ok: boolean): void {
    if (!hasEventTarget()) {
        return; // exotic runtime — nothing to notify.
    }

    bus.dispatchEvent(
        new CustomEvent<WebmcpToolActivityDetail>(WEBMCP_TOOL_ACTIVITY_EVENT, {
            detail: { tool, ok, at: new Date().toISOString() },
        }),
    );
}

/** True when this tool's success should refresh the Creative Room concept list. */
export function mutatesConceptList(tool: string): boolean {
    return CONCEPT_MUTATING_TOOLS.has(tool);
}

/**
 * Subscribe to concept-mutating WebMCP activity. Returns an unsubscribe
 * function. Safe on exotic runtimes (returns a noop unsubscribe).
 */
export function onConceptMutatingActivity(
    handler: (detail: WebmcpToolActivityDetail) => void,
): () => void {
    if (!hasEventTarget()) {
        return () => {};
    }

    const listener = (event: Event): void => {
        const detail = (event as CustomEvent<WebmcpToolActivityDetail>).detail;
        if (detail && mutatesConceptList(detail.tool) && detail.ok) {
            handler(detail);
        }
    };

    bus.addEventListener(WEBMCP_TOOL_ACTIVITY_EVENT, listener);

    return () => bus.removeEventListener(WEBMCP_TOOL_ACTIVITY_EVENT, listener);
}
