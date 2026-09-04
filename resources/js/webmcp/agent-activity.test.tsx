import { describe, expect, it } from 'vitest';
import { renderToString } from 'react-dom/server';
import AgentActivityFeed, {
    filterActivityEntries,
} from '@/Components/AgentActivityFeed';
import type { AgentActivityEntry } from '@/webmcp/api';

const entries: AgentActivityEntry[] = [
    {
        id: 4,
        agent: { name: 'Codex', is_agent: true },
        tool_name: 'inspect_photo',
        authority: 'READ',
        result_status: 'completed',
        summary_in: { photo_id: 4 },
        summary_out: { sharpness: 'sharp' },
        duration_ms: 412,
        created_at: '2026-09-04T04:04:00.000Z',
    },
    {
        id: 3,
        agent: { name: 'Codex', is_agent: true },
        tool_name: 'propose_cull',
        authority: 'PROPOSE',
        result_status: 'warning',
        summary_in: { body: '<script>not executable</script>' },
        summary_out: null,
        duration_ms: null,
        created_at: null,
    },
];

const noop = (): void => undefined;

describe('Agent activity feed', () => {
    it('filters tool names case-insensitively without changing the ledger', () => {
        expect(filterActivityEntries(entries, 'PHOTO')).toEqual([entries[0]]);
        expect(filterActivityEntries(entries, '')).toEqual(entries);
        expect(filterActivityEntries(entries, 'missing')).toEqual([]);
    });

    it('renders compact escaped ledger rows and the tool filter', () => {
        const html = renderToString(
            <AgentActivityFeed
                entries={entries}
                filter=""
                loading={false}
                error={null}
                hasOlder={false}
                loadingOlder={false}
                onFilterChange={noop}
                onLoadOlder={noop}
            />,
        );

        expect(html).toContain('data-testid="agent-activity-feed"');
        expect(html).toContain('Filter tools');
        expect(html).toContain('Codex');
        expect(html).toContain('inspect_photo');
        expect(html).toContain('READ');
        expect(html).toContain('completed');
        expect(html).toContain('412ms');
        expect(html).toContain('&lt;script&gt;not executable&lt;/script&gt;');
        expect(html).not.toContain('<script>not executable</script>');
    });

    it('renders the honest empty state when no activity exists', () => {
        const html = renderToString(
            <AgentActivityFeed
                entries={[]}
                filter=""
                loading={false}
                error={null}
                hasOlder={false}
                loadingOlder={false}
                onFilterChange={noop}
                onLoadOlder={noop}
            />,
        );

        expect(html).toContain(
            'No agent activity yet — external agents will appear here as they call tools.',
        );
    });
});
