import { describe, expect, it, vi } from 'vitest';
import { createElement, Fragment } from 'react';
import { renderToString } from 'react-dom/server';

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => createElement(Fragment, null, children),
    Link: ({ href, children, ...props }: { href: string; children?: React.ReactNode; [key: string]: unknown }) =>
        createElement('a', { href, ...props }, children),
    router: { reload: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: {} }),
}));

vi.mock('@/Components/Modal', () => ({
    default: ({ children, show }: { children: React.ReactNode; show: boolean }) =>
        show ? createElement('div', { 'data-testid': 'photo-delete-modal' }, children) : null,
}));

const WorkspacePage = await import('@/Pages/Workspace');

describe('Workspace audit regressions', () => {
    it('M1 clears the reject busy state when the network request rejects', async () => {
        const busyStates: (string | null)[] = [];
        const networkFailure = new Error('Network request failed');

        await expect(
            WorkspacePage.withBusyState(
                (value) => busyStates.push(value),
                'reject',
                vi.fn().mockRejectedValue(networkFailure),
            ),
        ).rejects.toThrow(networkFailure);

        expect(busyStates).toEqual(['reject', null]);
    });

    it('renders the selected-photo delete confirmation flow', () => {
        const html = renderToString(
            createElement(WorkspacePage.PhotoDeleteDialog, {
                show: true,
                photoName: 'frame-001.jpg',
                processing: false,
                onClose: vi.fn(),
                onConfirm: vi.fn(),
            }),
        );

        expect(html).toContain('data-testid="photo-delete-modal"');
        expect(html).toContain('Delete photo?');
        expect(html).toContain('frame-001.jpg');
        expect(html).toContain('workspace-delete-photo-cancel');
        expect(html).toContain('workspace-delete-photo-confirm');
        expect(html).toContain('Permanently delete frame-001.jpg');
        expect(html).toContain('permanently deleted');
    });

    it('disables the photo delete confirmation while the request is processing', () => {
        const html = renderToString(
            createElement(WorkspacePage.PhotoDeleteDialog, {
                show: true,
                photoName: 'frame-001.jpg',
                processing: true,
                onClose: vi.fn(),
                onConfirm: vi.fn(),
            }),
        );

        expect(html).toContain('DELETING');
        expect(html).toMatch(/workspace-delete-photo-confirm[^>]*disabled=""/);
    });

    it('M3 renders a canonical Creative Room link for the current project', () => {
        (globalThis as Record<string, unknown>).route = (name: string, projectId: number) =>
            name === 'creative.show' ? `/projects/${projectId}/creative` : `/${name}`;

        const html = renderToString(
            createElement(WorkspacePage.CreativeRoomLink, { projectId: 42 }),
        );

        expect(html).toContain('data-testid="workspace-creative-room-link"');
        expect(html).toContain('href="/projects/42/creative"');
        expect(html).toContain('Creative Room');
    });
});
