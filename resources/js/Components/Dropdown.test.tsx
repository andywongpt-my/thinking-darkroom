import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import Dropdown, {
    isDropdownEscapeKey,
    isOutsideDropdown,
    mergeDropdownRefs,
} from '@/Components/Dropdown';

const text = (html: string): string => html.replace(/<!-- -->/g, '');

describe('Dropdown', () => {
    it('renders a semantic trigger and 44px menu targets without changing the call-site API', () => {
        const html = renderToStaticMarkup(
            createElement(
                Dropdown,
                null,
                createElement(
                    Dropdown.Trigger,
                    null,
                    createElement('button', { type: 'button' }, 'Account'),
                ),
                createElement(
                    Dropdown.Content,
                    null,
                    createElement(Dropdown.Link, { href: '/profile' }, 'Profile'),
                ),
            ),
        );

        expect(text(html)).toContain('Account');
        expect(html).toContain('aria-haspopup="menu"');
        expect(html).toContain('aria-expanded="false"');
        expect(html).toContain('data-state="closed"');
        expect(html).toContain('focus-visible:ring-2');

        const linkHtml = renderToStaticMarkup(
            createElement(Dropdown.Link, { href: '/profile' }, 'Profile'),
        );
        expect(linkHtml).toContain('role="menuitem"');
        expect(linkHtml).toContain('min-h-11');
    });

    it('recognizes Escape as the close key and distinguishes inside from outside pointer targets', () => {
        const inside = {} as Node;
        const outside = {} as Node;
        const container = {
            contains: (target: Node) => target === inside,
        };

        expect(isDropdownEscapeKey('Escape')).toBe(true);
        expect(isDropdownEscapeKey('Enter')).toBe(false);
        expect(isOutsideDropdown(container, inside)).toBe(false);
        expect(isOutsideDropdown(container, outside)).toBe(true);
        expect(isOutsideDropdown(null, outside)).toBe(false);
    });

    it('preserves an existing trigger ref while recording the dropdown trigger node', () => {
        const originalRef: { current: HTMLElement | null } = { current: null };
        const observed: Array<HTMLElement | null> = [];
        const setRef = mergeDropdownRefs<HTMLElement>(originalRef, (node) => observed.push(node));
        const node = {} as HTMLElement;

        setRef(node);
        expect(originalRef.current).toBe(node);
        expect(observed).toEqual([node]);

        setRef(null);

        expect(originalRef.current).toBe(null);
        expect(observed).toEqual([node, null]);
    });
});
