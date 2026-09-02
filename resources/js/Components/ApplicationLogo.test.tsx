import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import ApplicationLogo from './ApplicationLogo';

describe('Thinking Darkroom application logo', () => {
    it('renders an accessible original mark with its safelight accent', () => {
        const html = renderToStaticMarkup(createElement(ApplicationLogo));

        expect(html).toContain('data-testid="thinking-darkroom-logo"');
        expect(html).toContain('role="img"');
        expect(html).toContain('aria-label="Thinking Darkroom"');
        expect(html).toContain('data-part="safelight"');
        expect(html).toContain('viewBox="0 0 64 64"');
    });
});
