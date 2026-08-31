/**
 * Guest-only WebMCP tools for the authentication bootstrap.
 *
 * This registry is deliberately kept outside WebmcpToolCatalog: the server
 * catalogue describes session-authenticated workspace tools and the creative
 * authority ladder, while authentication bootstrap is a prerequisite for that
 * ladder rather than a creative authority. The app bootstrap calls this only
 * when the current Inertia page has no authenticated user.
 */

import { getModelContext, isWebmcpAvailable, registerTool, unregisterTool } from './model-context';
import type { ModelContext, ModelContextTool } from './tool-types';

interface GuestToolResult {
    success: boolean;
    message: string;
    status?: number;
}

const SITE_INFO = {
    app_name: 'Thinking Darkroom',
    positioning: 'WebMCP-native collaborative photography workspace',
    demo_credentials: {
        photographer: {
            email: 'photographer@webmcp.test',
            password: 'password',
        },
        agent: {
            email: 'agent@webmcp.test',
            password: 'password',
        },
    },
    hint: 'Use the login tool with one of these credentials to continue.',
} as const;

function csrfToken(): string {
    if (typeof document === 'undefined') return '';

    return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
}

function buildLoginTool(): ModelContextTool {
    return {
        name: 'login',
        description: 'Signs in to Thinking Darkroom with a demo or project account.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {
                email: {
                    type: 'string',
                    description: 'Account email address.',
                },
                password: {
                    type: 'string',
                    description: 'Account password.',
                },
                remember: {
                    type: 'boolean',
                    description: 'Keep the session signed in.',
                },
            },
            required: ['email', 'password'],
        },
        annotations: { readOnlyHint: false },
        execute: async (args): Promise<GuestToolResult> => {
            const body: Record<string, unknown> = {
                email: String(args.email ?? ''),
                password: String(args.password ?? ''),
            };
            if (typeof args.remember === 'boolean') {
                body.remember = args.remember;
            }

            try {
                const response = await fetch('/login', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify(body),
                });

                if (response.status === 422) {
                    return { success: false, message: 'Invalid credentials' };
                }

                if (response.ok || (response.status >= 300 && response.status < 400)) {
                    // Laravel answers an invalid HTML-form login with a 302 back
                    // to /login (not a 422). fetch silently follows that bounce,
                    // so a 200 whose final URL is still /login means rejection.
                    const finalPath = new URL(response.url ?? '', 'http://localhost').pathname;
                    if (finalPath === '/login') {
                        return { success: false, message: 'Invalid credentials' };
                    }

                    window.location.href = '/dashboard';
                    return { success: true, message: 'Logged in successfully' };
                }

                return {
                    success: false,
                    message: 'Login failed',
                    status: response.status,
                };
            } catch {
                return { success: false, message: 'Network error while logging in' };
            }
        },
    };
}

function buildSiteInfoTool(): ModelContextTool {
    return {
        name: 'get_site_info',
        description: 'Returns Thinking Darkroom identity, public demo credentials, and the login hint.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {},
            required: [],
        },
        annotations: { readOnlyHint: true },
        execute: async () => SITE_INFO,
    };
}

/**
 * Register the two guest tools on the live WebMCP context.
 *
 * The caller is responsible for the authentication-state check; this function
 * is intentionally side-effect free when the browser has no WebMCP support.
 */
export function mountGuestRegistry(): () => void {
    if (!isWebmcpAvailable()) return () => undefined;

    const context = getModelContext();
    if (!context) return () => undefined;

    const tools = [buildLoginTool(), buildSiteInfoTool()];
    const registrations = tools.map((tool) => ({
        tool,
        abort: new AbortController(),
    }));
    let mounted = true;

    for (const { tool, abort } of registrations) {
        registerTool(tool, abort.signal, context);
    }

    return () => {
        if (!mounted) return;
        mounted = false;

        for (const { tool, abort } of registrations) {
            unregisterTool(tool.name, abort, context);
        }
    };
}

export type { GuestToolResult };
export { SITE_INFO };
