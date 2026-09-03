import { describe, expect, it, vi } from 'vitest';
import { createElement, Fragment } from 'react';
import { renderToString } from 'react-dom/server';
import { autoDismissNotification } from '@/webmcp/notifications';

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

        expect(html).toContain('Deleting');
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

    it('C2 renders the photographer decision ledger with a note and timestamp', () => {
        const html = renderToString(
            createElement(WorkspacePage.DecisionLedger, {
                decisions: [{
                    id: 12,
                    proposal_id: 4,
                    photographer: 'Maya',
                    decision: 'keep',
                    note: 'The expression carries the frame.',
                    decided_at: '2026-09-02T03:04:05.000Z',
                }],
            }),
        );

        expect(html).toContain('data-testid="decision-history-panel"');
        expect(html).toContain('KEEP');
        expect(html).toContain('The expression carries the frame.');
        expect(html).toContain('Maya');
        expect(html).toContain('2026');
    });

    it('C3 renders the selected photo lens from the eager inspection map over server EXIF', () => {
        const html = renderToString(
            createElement(WorkspacePage.PhotoExifGrid, {
                photo: {
                    id: 1,
                    filename: 'frame-001.jpg',
                    url: null,
                    mime: 'image/jpeg',
                    width: 1000,
                    height: 667,
                    size_bytes: 1,
                    selection_state: 'unreviewed',
                    retouch_state: 'none',
                    camera_model: null,
                    iso: null,
                    original_name: null,
                    lens: null,
                    aperture: null,
                    shutter_speed: null,
                    focal_length: null,
                    dimensions: null,
                },
                inspected: {
                    lens: '50mm f/1.8',
                    dimensions: '1000×667',
                },
            }),
        );

        expect(html).toContain('data-testid="photo-exif-grid"');
        expect(html).toContain('Lens:');
        expect(html).toContain('50mm f/1.8');
        expect(html).toContain('1000×667');
    });

    it('A6 keeps execute blocked until the confirmation callback runs', () => {
        const target = { id: 42 };
        const runExecute = vi.fn();
        let confirm!: () => void;
        const html = renderToString(
            createElement(WorkspacePage.WorkspaceConfirmDialog, {
                show: true,
                title: 'Execute plan?',
                description: 'This applies 2 operations to 2 photos.',
                processing: false,
                confirmTestId: 'workspace-confirm-execute',
                onClose: vi.fn(),
                onConfirm: () => {
                    WorkspacePage.runAfterConfirmation(target, true, runExecute);
                },
            }),
        );
        confirm = () => WorkspacePage.runAfterConfirmation(target, true, runExecute);

        expect(html).toContain('workspace-confirm-execute');
        expect(html).toContain('2 photos');
        expect(WorkspacePage.runAfterConfirmation(target, false, runExecute)).toBe(false);
        expect(runExecute).not.toHaveBeenCalled();

        confirm();
        expect(runExecute).toHaveBeenCalledWith(target);
    });

    it('A20 auto-dismisses a notification after five seconds but not while busy', () => {
        vi.useFakeTimers();
        try {
            const notifications = vi.fn();
            const timerRef: { current: ReturnType<typeof setTimeout> | null } = { current: null };
            const cleanup = autoDismissNotification(
                { kind: 'ok', text: 'Saved.' },
                null,
                notifications,
                timerRef,
            );

            vi.advanceTimersByTime(4999);
            expect(notifications).not.toHaveBeenCalled();
            vi.advanceTimersByTime(1);
            expect(notifications).toHaveBeenCalledWith(null);
            cleanup();

            const busyNotifications = vi.fn();
            const busyTimerRef: { current: ReturnType<typeof setTimeout> | null } = { current: null };
            const busyCleanup = autoDismissNotification(
                { kind: 'ok', text: 'Still processing.' },
                'execute',
                busyNotifications,
                busyTimerRef,
            );
            vi.advanceTimersByTime(5000);
            expect(busyNotifications).not.toHaveBeenCalled();
            busyCleanup();
        } finally {
            vi.useRealTimers();
        }
    });

    it('clears the file input so the same photo can be selected again', () => {
        const input = { value: 'selected-photo' };

        WorkspacePage.resetFileInput(input);

        expect(input.value).toBe('');
    });
});
