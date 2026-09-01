import { describe, expect, it, vi } from 'vitest';
import { createElement, Fragment } from 'react';

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => createElement(Fragment, null, children),
    Link: ({ children, ...props }: { children?: React.ReactNode; [key: string]: unknown }) =>
        createElement('a', props, children),
    router: { reload: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: {} }),
}));

const WorkspacePage = await import('@/Pages/Workspace');

describe('Workspace reject handling', () => {
    it('shows a safe error notification after a rejected network request', async () => {
        const busyStates: (string | null)[] = [];
        const notifications: { kind: string; text: string }[] = [];
        const networkFailure = new Error('network secret');

        const result = await WorkspacePage.withHumanRejectErrorHandling(
            (value) => busyStates.push(value),
            vi.fn().mockRejectedValue(networkFailure),
            (notification) => notifications.push(notification),
        );

        expect(result).toBeUndefined();
        expect(busyStates).toEqual(['reject', null]);
        expect(notifications).toEqual([
            { kind: 'err', text: 'Reject failed. Please try again.' },
        ]);
    });
});
