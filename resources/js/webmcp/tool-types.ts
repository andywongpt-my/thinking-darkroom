/**
 * WebMCP challenge — shared TypeScript types for the WebMCP tool layer.
 *
 * These mirror the server-side catalog (App\Support\WebmcpToolCatalog) and
 * the controller response shapes, so the browser registry stays consistent
 * with what the Laravel API actually enforces.
 */

/** Creative authority levels from the domain model. */
export type WebmcpAuthority = 'READ' | 'PROPOSE' | 'EXECUTE';

/** A JSON Schema property definition (subset we emit). */
export interface JsonSchemaProperty {
    type: 'string' | 'integer' | 'boolean' | 'array' | 'object' | 'number';
    description?: string;
    enum?: string[];
    items?: JsonSchemaProperty;
    properties?: Record<string, JsonSchemaProperty>;
    required?: string[];
    additionalProperties?: boolean;
    minimum?: number;
    maximum?: number;
}

/** The JSON Schema object a WebMCP tool registers with. */
export interface ToolInputSchema {
    type: 'object';
    additionalProperties: false;
    properties: Record<string, JsonSchemaProperty>;
    required: string[];
}

interface ToolAnnotation {
    readOnlyHint: boolean;
}

/** Shape of document.modelContext.registerTool (WebMCP browser API). */
export interface ModelContextTool {
    name: string;
    description: string;
    inputSchema: ToolInputSchema;
    annotations?: ToolAnnotation;
    execute: (args: Record<string, unknown>) => Promise<unknown>;
}

/** The WebMCP browser API on `document`. */
export interface ModelContext {
    available: boolean;
    registerTool: (
        tool: ModelContextTool,
        options?: { signal?: AbortSignal },
    ) => void | Promise<unknown>;
    unregisterTool?: (name: string) => void | Promise<unknown>;
    listTools?: () => Promise<{ name: string }[]> | { name: string }[];
}

/** One entry in the server-side authoritative tool catalog. */
export interface CatalogToolMeta {
    name: string;
    authority: WebmcpAuthority;
    method: 'GET' | 'POST';
    path: string;
    read_only: boolean;
    description: string;
    dynamic: boolean;
}

/** Stack of authority a tool carries into the browser registry. */
export interface RegisteredToolMeta {
    name: string;
    authority: WebmcpAuthority;
    dynamic: boolean;
    registeredAt: string;
}
