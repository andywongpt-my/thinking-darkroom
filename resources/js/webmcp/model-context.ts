import type { ModelContext, ModelContextTool } from './tool-types';

/**
 * Feature detection for the WebMCP browser API.
 *
 * The current spec exposes `document.modelContext`. We MUST NOT build around
 * the deprecated `navigator.modelContext` — if the modern API is missing we
 * degrade gracefully (app still loads, tools rendered unavailable) and never
 * fall back to the deprecated surface.
 */

declare global {
    interface Document {
        modelContext?: ModelContext;
    }
}

let cachedAvailable: boolean | undefined;
let cachedContext: ModelContext | null = null;

/** True when the modern WebMCP API is present on `document`. */
export function isWebmcpAvailable(): boolean {
    if (cachedAvailable !== undefined) return cachedAvailable;

    if (typeof document !== 'undefined' && typeof document.modelContext?.registerTool === 'function') {
        cachedContext = document.modelContext!;
        cachedAvailable = true;
    } else {
        cachedContext = null;
        cachedAvailable = false;
    }

    return cachedAvailable;
}

/** Test/dev hook: clear the memoised feature-detection result. */
export function resetWebmcpDetection(): void {
    cachedAvailable = undefined;
    cachedContext = null;
}

/** Returns the live model context if available, else null. */
export function getModelContext(): ModelContext | null {
    return isWebmcpAvailable() ? cachedContext : null;
}

/** Register a tool on a WebMCP context. No-op when unavailable. */
export function registerTool(
    tool: ModelContextTool,
    signal?: AbortSignal,
    context: ModelContext | null = null,
): boolean {
    const ctx = context ?? getModelContext();
    if (!ctx) return false;
    if (signal) {
        ctx.registerTool(tool, { signal });
    } else {
        ctx.registerTool(tool);
    }
    return true;
}

/**
 * Unregister a tool by aborting its registration signal and, where supported,
 * calling the browser's explicit unregister method too.
 */
export function unregisterTool(
    name: string,
    abort?: AbortController,
    context: ModelContext | null = null,
): boolean {
    const ctx = context ?? getModelContext();
    let unregistered = false;

    if (abort) {
        abort.abort();
        unregistered = true;
    }

    if (ctx && typeof ctx.unregisterTool === 'function') {
        ctx.unregisterTool(name);
        unregistered = true;
    }

    return unregistered;
}

/** Best-effort list of the names currently registered on the live API. */
export async function listRegisteredToolNames(): Promise<string[]> {
    const ctx = getModelContext();
    if (!ctx) return [];
    try {
        const raw =
            typeof ctx.listTools === 'function' ? await ctx.listTools() : [];
        return raw.map((t) => t.name);
    } catch {
        return [];
    }
}

/**
 * Development-only in-page fallback "Model Context" used so the diagnostics
 * panel and the registry still function in browsers without WebMCP support.
 * It is NOT wire-compatible with anything — it exists purely to demonstrate
 * lifecycle behaviour while the platform ships `document.modelContext`.
 */
export function createInMemoryModelContext(): ModelContext {
    const tools = new Map<string, ModelContextTool>();

    return {
        available: true,
        registerTool(tool, options) {
            tools.set(tool.name, tool);

            if (options?.signal) {
                const remove = () => tools.delete(tool.name);

                if (options.signal.aborted) {
                    remove();
                } else {
                    options.signal.addEventListener('abort', remove, { once: true });
                }
            }
        },
        unregisterTool(name) {
            tools.delete(name);
        },
        listTools() {
            return [...tools.values()].map((t) => ({ name: t.name }));
        },
    };
}
