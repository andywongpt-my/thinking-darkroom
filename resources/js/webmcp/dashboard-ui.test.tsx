/**
 * Dashboard darkroom redesign — rendering + contract tests.
 *
 * Runs on the EXISTING Vitest stack (react-dom/server, mocked module
 * boundaries — same pattern as the Sprint 2/3 suites). Everything under the
 * mocks (the page component) runs as real code.
 *
 * Coverage:
 *  1. Project film card renders with the canonical testid
 *  2. Sprocket strips render (film identity)
 *  3. Project stats render: photos / awaiting-you / executed
 *  4. Pending proposals surface in the amber safelight accent
 *  5. Agent authority ladder renders all four rungs with counts
 *  6. Tool inventory total renders in the mono readout
 *  7. Dynamic apply_approved_plan narrative renders
 *  8. Agent presence renders ONLINE + agent name
 *  9. Stale presence renders OFFLINE
 * 10. Backward compatibility: page still renders without the new props
 * 11. Empty state renders when the darkroom has no film
 * 12. Status label mapping: active → IN PROGRESS
 * 13. Create-project trigger and form accessibility contract
 * 14. Validation focus selects name before description
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { createElement, Fragment } from 'react';
import { renderToString } from 'react-dom/server';

/* --------------------------------------------------------------- inertia mock */

type PageFixture = Record<string, unknown> & {
    props: Record<string, unknown>;
};

let pageFixture: PageFixture;
let formProcessing = false;
let formErrors: Record<string, string> = {};

vi.mock('@inertiajs/react', () => {
    const Head = ({ children }: { children?: React.ReactNode }) =>
        createElement(Fragment, null, children);
    // Faithful to real Inertia Link: renders an <a> and forwards className,
    // data-* testids and other DOM attributes through.
    const Link = (props: Record<string, unknown>) => {
        const { href, method, as, children, ...rest } = props;
        return createElement('a', { href: String(href ?? '#'), ...rest }, children as React.ReactNode);
    };
    const useForm = (initialData: Record<string, string>) => ({
        data: initialData,
        setData: vi.fn(),
        post: vi.fn(),
        errors: formErrors,
        processing: formProcessing,
        reset: vi.fn(),
        clearErrors: vi.fn(),
    });
    return {
        Head,
        Link,
        router: { reload: vi.fn(), visit: vi.fn() },
        useForm,
        usePage: () => pageFixture,
    };
});

vi.mock('@/Components/Modal', () => ({
    default: ({ children, show }: { children: React.ReactNode; show: boolean }) =>
        createElement('div', { 'data-testid': 'new-project-dialog', 'data-open': String(show) }, children),
}));

vi.mock('@/Layouts/AuthenticatedLayout', () => ({
    default: ({ header, children }: { header?: React.ReactNode; children: React.ReactNode }) =>
        createElement(
            'div',
            { 'data-testid': 'authenticated-shell' },
            createElement('header', null, header),
            createElement('main', null, children),
        ),
}));

const DashboardModule = await import('@/Pages/Dashboard');
const DashboardPage = DashboardModule.default as React.FC;
const { focusFirstProjectField } = DashboardModule;

/* -------------------------------------------------------------------- fixture */

function makeProps(overrides: Record<string, unknown> = {}): PageFixture['props'] {
    return {
        auth: { user: { id: 7, name: 'Maya Tanaka', email: 'photographer@webmcp.test' } },
        projects: [
            {
                id: 1,
                name: 'Coastal Studio — Editorial Portraits',
                status: 'active',
                photo_count: 127,
                pending_proposals: 2,
                url: '/projects/1',
            },
        ],
        project_meta: {
            1: { approved_proposals: 1, executed_proposals: 9, last_photo_at: '2026-08-30T14:02:00Z' },
        },
        tools: {
            total: 22,
            byAuthority: { READ: 12, ANALYZE: 2, PROPOSE: 7, EXECUTE: 1 },
            dynamic: {
                name: 'apply_approved_plan',
                description: 'Executes an approved, unexecuted proposal. Only registered after photographer approval.',
            },
        },
        agent: { name: 'WebMCP Agent', online: true, last_seen_at: '2026-08-31T02:00:00Z' },
        now: '2026-08-31T02:00:00Z',
        ...overrides,
    };
}

function render(): string {
    // Inertia passes page props directly to the page component.
    return renderToString(createElement(DashboardPage, pageFixture.props as never));
}

beforeEach(() => {
    pageFixture = { props: makeProps() };
    formProcessing = false;
    formErrors = {};
});

/* ---------------------------------------------------------------------- tests */

describe('Dashboard darkroom view', () => {
    it('renders the project film card with the canonical testid', () => {
        expect(render()).toContain('data-testid="dashboard-project-1"');
    });

    it('renders film sprocket strips for darkroom identity', () => {
        const html = render();
        // Sprocket holes: 10 per strip × 2 strips on the film card.
        expect((html.match(/bg-black\/60/g) ?? []).length).toBeGreaterThanOrEqual(20);
    });

    it('renders project stats: photos, awaiting you, executed', () => {
        const html = render();
        expect(html).toContain('127');
        expect(html).toContain('awaiting you');
        expect(html).toContain('executed');
    });

    it('surfaces pending proposals in the amber safelight accent', () => {
        expect(render()).toContain('text-amber-400');
    });

    it('renders the agent authority ladder with all four rungs', () => {
        const html = render();
        expect(html).toContain('READ');
        expect(html).toContain('ANALYZE');
        expect(html).toContain('PROPOSE');
        expect(html).toContain('EXECUTE');
        expect(html).toContain('exists only after approval');
    });

    it('renders the tool inventory total in the mono readout', () => {
        const html = render();
        expect(html).toContain('data-testid="dashboard-tool-count"');
        expect(html).toMatch(/22<!-- --> tools|22 tools/);
    });

    it('renders the dynamic apply_approved_plan narrative', () => {
        expect(render()).toContain('apply_approved_plan');
        expect(render()).toContain('unregistered the instant it runs');
    });

    it('renders agent presence ONLINE with the agent name', () => {
        const html = render();
        expect(html).toContain('ONLINE');
        expect(html).toContain('WebMCP Agent');
    });

    it('renders OFFLINE for stale presence', () => {
        pageFixture = {
            props: makeProps({ agent: { name: 'WebMCP Agent', online: false, last_seen_at: '2026-08-29T02:00:00Z' } }),
        };
        expect(render()).toContain('OFFLINE');
    });

    it('still renders without the new optional props (old payload shape)', () => {
        pageFixture = {
            props: makeProps({ tools: undefined, agent: undefined, now: undefined }),
        };
        const html = render();
        expect(html).toContain('data-testid="dashboard-project-1"');
        expect(html).not.toContain('Agent authority ladder');
    });

    it('renders the empty state when there is no film', () => {
        pageFixture = { props: makeProps({ projects: [] }) };
        expect(render()).toContain('No film in the darkroom yet');
    });

    it('maps project status to darkroom labels', () => {
        expect(render()).toContain('IN PROGRESS');
    });

    it('renders ADD NEW PROJECT and its form for a human with or without existing projects', () => {
        for (const projects of [makeProps().projects, []]) {
            pageFixture = { props: makeProps({ projects, can_create_project: true }) };
            const html = render();

            expect(html).toContain('data-testid="dashboard-add-project"');
            expect(html).toContain('ADD NEW PROJECT');
            expect(html).toContain('data-testid="new-project-dialog"');
            expect(html).toContain('<form');
            expect(html).toContain('aria-labelledby="new-project-title"');
            expect(html).not.toContain('aria-label="Create new project"');
            expect(html).toMatch(/data-testid="dashboard-add-project"[^>]*aria-haspopup="dialog"/);
            expect(html).toMatch(/data-testid="dashboard-add-project"[^>]*aria-expanded="false"/);
            expect(html).toContain('for="project-name"');
            expect(html).toContain('id="project-name"');
            expect(html).toContain('name="name"');
            expect(html).toContain('required=""');
            expect(html).toContain('for="project-description"');
            expect(html).toContain('id="project-description"');
            expect(html).toContain('name="description"');
            expect(html).toContain('CANCEL');
            expect(html).toContain('CREATE PROJECT');
        }
    });

    it('disables project inputs while the request is processing', () => {
        formProcessing = true;
        pageFixture = { props: makeProps({ can_create_project: true }) };
        const html = render();

        expect(html).toMatch(/id="project-name"[^>]*disabled=""/);
        expect(html).toMatch(/id="project-description"[^>]*disabled=""/);
    });

    it('focuses the first project field represented in validation errors', () => {
        const nameFocus = vi.fn();
        const descriptionFocus = vi.fn();
        const nameInput = { current: { focus: nameFocus } };
        const descriptionInput = { current: { focus: descriptionFocus } };

        focusFirstProjectField({ name: 'Name is required', description: 'Description is too long' }, nameInput, descriptionInput);
        expect(nameFocus).toHaveBeenCalledOnce();
        expect(descriptionFocus).not.toHaveBeenCalled();

        nameFocus.mockClear();
        descriptionFocus.mockClear();
        focusFirstProjectField({ description: 'Description is too long' }, nameInput, descriptionInput);
        expect(nameFocus).not.toHaveBeenCalled();
        expect(descriptionFocus).toHaveBeenCalledOnce();
    });

    it('does not expose project creation to a machine agent', () => {
        pageFixture = { props: makeProps({ can_create_project: false }) };
        const html = render();

        expect(html).not.toContain('ADD NEW PROJECT');
        expect(html).not.toContain('data-testid="new-project-dialog"');
    });
});
