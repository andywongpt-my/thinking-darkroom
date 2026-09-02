import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }: { children: React.ReactNode }) => createElement('a', props, children),
    usePage: () => ({ props: { auth: { user: { name: 'Maya', email: 'maya@example.test' } } } }),
}));

vi.mock('@/Components/Dropdown', () => {
    const Dropdown = ({ children }: { children: React.ReactNode }) => createElement('div', null, children);
    const Trigger = ({ children }: { children: React.ReactNode }) => createElement('div', null, children);
    const Content = ({ children }: { children: React.ReactNode }) => createElement('div', null, children);
    const Link = ({ children }: { children: React.ReactNode }) => createElement('a', null, children);

    return { default: Object.assign(Dropdown, { Trigger, Content, Link }) };
});

vi.mock('@/Components/NavLink', () => ({
    default: ({ children, active: _active, ...props }: { children: React.ReactNode; active: boolean }) => createElement('a', props, children),
}));

vi.mock('@/Components/ResponsiveNavLink', () => ({
    default: ({ children, active: _active, ...props }: { children: React.ReactNode; active?: boolean }) => createElement('a', props, children),
}));

Object.assign(globalThis, {
    route: (name?: string) => (name === undefined ? { current: () => false } : `/${name}`),
});

const { default: AuthenticatedLayout } = await import('@/Layouts/AuthenticatedLayout');

describe('Thinking Darkroom authenticated identity', () => {
    it('keeps an accessible full brand lockup in the primary navigation', () => {
        const html = renderToStaticMarkup(
            createElement(AuthenticatedLayout, null, createElement('p', null, 'Workspace content')),
        );

        expect(html).toContain('data-testid="primary-brand-lockup"');
        expect(html).toContain('aria-label="Thinking Darkroom home"');
        expect(html).toContain('data-testid="thinking-darkroom-wordmark"');
        expect(html).toContain('Thinking');
        expect(html).toContain('Darkroom');
    });
});
