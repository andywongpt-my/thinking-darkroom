import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mountGuestRegistry } from './guest-registry';
import { resetWebmcpDetection } from './model-context';
import type { ModelContextTool } from './tool-types';

interface FakeWebmcp {
    available: boolean;
    registerTool(tool: ModelContextTool, options?: { signal?: AbortSignal }): void;
    unregisterTool(name: string): void;
    names(): string[];
    tool(name: string): ModelContextTool | undefined;
}

function makeFake(): FakeWebmcp {
    const tools = new Map<string, ModelContextTool>();
    const fake: FakeWebmcp = {
        available: true,
        registerTool(tool, options) {
            tools.set(tool.name, tool);
            options?.signal?.addEventListener('abort', () => tools.delete(tool.name), { once: true });
        },
        unregisterTool(name) {
            tools.delete(name);
        },
        names: () => [...tools.keys()].sort(),
        tool: (name) => tools.get(name),
    };

    return fake;
}

function installDocument(ctx: FakeWebmcp, csrfToken = 'csrf-token') {
    vi.stubGlobal('document', {
        modelContext: ctx,
        querySelector: vi.fn((selector: string) =>
            selector === 'meta[name="csrf-token"]' ? { content: csrfToken } : null,
        ),
    });
    vi.stubGlobal('window', { location: { href: '' } });
    resetWebmcpDetection();
}

describe('guest WebMCP registry', () => {
    beforeEach(() => {
        resetWebmcpDetection();
    });

    afterEach(() => {
        resetWebmcpDetection();
        vi.unstubAllGlobals();
    });

    it('registers exactly login and get_site_info for an unauthenticated bootstrap', () => {
        const fake = makeFake();
        installDocument(fake);

        const cleanup = mountGuestRegistry();

        expect(fake.names()).toEqual(['get_site_info', 'login']);
        expect(fake.tool('login')?.inputSchema).toEqual({
            type: 'object',
            additionalProperties: false,
            properties: {
                email: { type: 'string', description: 'Account email address.' },
                password: { type: 'string', description: 'Account password.' },
                remember: { type: 'boolean', description: 'Keep the session signed in.' },
            },
            required: ['email', 'password'],
        });
        expect(fake.tool('get_site_info')?.inputSchema).toEqual({
            type: 'object',
            additionalProperties: false,
            properties: {},
            required: [],
        });

        cleanup();
    });

    it('does not register guest tools when WebMCP is unavailable', () => {
        vi.stubGlobal('document', {});
        resetWebmcpDetection();

        const cleanup = mountGuestRegistry();

        expect(cleanup).toEqual(expect.any(Function));
        cleanup();
    });

    it('posts login credentials and navigates to the dashboard on success', async () => {
        const fake = makeFake();
        installDocument(fake, 'meta-csrf-token');
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 200 } as Response);
        vi.stubGlobal('fetch', fetchMock);
        const cleanup = mountGuestRegistry();

        const result = await fake.tool('login')?.execute({
            email: 'photographer@webmcp.test',
            password: 'password',
            remember: true,
        });

        expect(fetchMock).toHaveBeenCalledWith('/login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'text/html, application/xhtml+xml',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': 'meta-csrf-token',
            },
            body: JSON.stringify({
                email: 'photographer@webmcp.test',
                password: 'password',
                remember: true,
            }),
        });
        expect(result).toEqual({ success: true, message: 'Logged in successfully' });
        expect(window.location.href).toBe('/dashboard');

        cleanup();
    });

    it('returns a structured error for invalid credentials without navigating', async () => {
        const fake = makeFake();
        installDocument(fake);
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 422 } as Response));
        const cleanup = mountGuestRegistry();

        const result = await fake.tool('login')?.execute({
            email: 'wrong@example.test',
            password: 'wrong-password',
        });

        expect(result).toEqual({ success: false, message: 'Invalid credentials' });
        expect(window.location.href).toBe('');

        cleanup();
    });

    it('returns a structured error when the login request cannot reach the server', async () => {
        const fake = makeFake();
        installDocument(fake);
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
        const cleanup = mountGuestRegistry();

        const result = await fake.tool('login')?.execute({
            email: 'agent@webmcp.test',
            password: 'password',
        });

        expect(result).toEqual({ success: false, message: 'Network error while logging in' });

        cleanup();
    });

    it('returns site information with both public demo credentials', async () => {
        const fake = makeFake();
        installDocument(fake);
        const cleanup = mountGuestRegistry();

        const info = await fake.tool('get_site_info')?.execute({});

        expect(info).toEqual({
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
        });

        cleanup();
    });

    it('removes both guest tools after unmount cleanup', () => {
        const fake = makeFake();
        installDocument(fake);

        const cleanup = mountGuestRegistry();
        expect(fake.names()).toEqual(['get_site_info', 'login']);

        cleanup();

        expect(fake.names()).toEqual([]);
    });
});
