import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }: { children: React.ReactNode }) => createElement('a', props, children),
}));

const { default: GuestLayout } = await import('@/Layouts/GuestLayout');

describe('Thinking Darkroom guest identity', () => {
    it('presents the full brand lockup through an accessible home link', () => {
        const html = renderToStaticMarkup(
            createElement(GuestLayout, null, createElement('p', null, 'Authentication form')),
        );

        expect(html).toContain('aria-label="Thinking Darkroom home"');
        expect(html).toContain('Thinking');
        expect(html).toContain('Darkroom');
        expect(html).toContain('data-testid="thinking-darkroom-logo"');
    });
});
