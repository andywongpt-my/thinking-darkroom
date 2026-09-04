import { ChangeEvent } from 'react';
import type { AgentActivityEntry } from '@/webmcp/api';

const SUMMARY_MAX_LENGTH = 96;

export interface AgentActivityFeedProps {
    entries: AgentActivityEntry[];
    filter: string;
    loading: boolean;
    error: string | null;
    hasOlder: boolean;
    loadingOlder: boolean;
    onFilterChange: (value: string) => void;
    onLoadOlder: () => void;
}

export function filterActivityEntries(
    entries: AgentActivityEntry[],
    filter: string,
): AgentActivityEntry[] {
    const normalizedFilter = filter.trim().toLocaleLowerCase();

    if (normalizedFilter.length === 0) return entries;

    return entries.filter((entry) => entry.tool_name.toLocaleLowerCase().includes(normalizedFilter));
}

function formatSummary(value: Record<string, unknown> | null): string | null {
    if (value === null) return null;

    let serialized: string;
    try {
        serialized = JSON.stringify(value) ?? String(value);
    } catch {
        serialized = '[summary unavailable]';
    }

    return serialized.length > SUMMARY_MAX_LENGTH
        ? `${serialized.slice(0, SUMMARY_MAX_LENGTH - 1)}…`
        : serialized;
}

function formatActivityTime(value: string | null): string {
    if (value === null) return 'time unknown';

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'time unknown';

    return date.toLocaleTimeString([], {
        hour: 'numeric',
        minute: '2-digit',
    });
}

function activityLine(entry: AgentActivityEntry): string {
    const parts = [
        entry.agent.name,
        entry.tool_name,
        entry.authority,
        entry.result_status,
        entry.duration_ms === null ? null : `${entry.duration_ms}ms`,
        formatActivityTime(entry.created_at),
    ].filter((part): part is string => part !== null);
    const summaryIn = formatSummary(entry.summary_in);
    const summaryOut = formatSummary(entry.summary_out);

    if (summaryIn !== null) parts.push(`in: ${summaryIn}`);
    if (summaryOut !== null) parts.push(`out: ${summaryOut}`);

    return parts.join(' · ');
}

export default function AgentActivityFeed({
    entries,
    filter,
    loading,
    error,
    hasOlder,
    loadingOlder,
    onFilterChange,
    onLoadOlder,
}: AgentActivityFeedProps) {
    const visibleEntries = filterActivityEntries(entries, filter);

    return (
        <section
            data-testid="agent-activity-feed"
            aria-label="Agent activity"
            className="flex min-h-64 flex-1 flex-col overflow-hidden bg-zinc-950/60"
        >
            <div className="border-b border-zinc-800/70 bg-zinc-900/30 px-4 py-3">
                <label htmlFor="agent-activity-tool-filter" className="text-xs font-semibold text-zinc-400">
                    Filter tools
                </label>
                <input
                    id="agent-activity-tool-filter"
                    type="search"
                    value={filter}
                    onChange={(event: ChangeEvent<HTMLInputElement>) => onFilterChange(event.target.value)}
                    placeholder="Filter by tool name…"
                    className="mt-1 w-full rounded-lg border-zinc-700 bg-zinc-950/80 text-sm text-zinc-100 shadow-none focus:border-amber-400/60 focus:ring-amber-400/60"
                />
            </div>

            {error !== null && (
                <p role="alert" className="border-b border-rose-500/30 bg-rose-500/10 px-4 py-2 text-xs text-rose-400">
                    {error}
                </p>
            )}

            <div
                role="log"
                aria-live="polite"
                aria-relevant="additions"
                className="flex-1 space-y-1 overflow-y-auto px-4 py-3"
            >
                {loading && entries.length === 0 ? (
                    <p role="status" className="py-8 text-center text-xs text-zinc-500">
                        Loading agent activity…
                    </p>
                ) : entries.length === 0 ? (
                    <p role="status" className="py-8 text-center text-xs leading-relaxed text-zinc-500">
                        No agent activity yet — external agents will appear here as they call tools.
                    </p>
                ) : visibleEntries.length === 0 ? (
                    <p role="status" className="py-8 text-center text-xs text-zinc-500">
                        No activity matches this tool filter.
                    </p>
                ) : (
                    visibleEntries.map((entry) => (
                        <article
                            key={entry.id}
                            data-testid={`agent-activity-entry-${entry.id}`}
                            title={activityLine(entry)}
                            className="min-w-0 rounded-lg border border-zinc-800/70 bg-zinc-900/50 px-3 py-2"
                        >
                            <p className="overflow-hidden text-ellipsis whitespace-nowrap text-xs leading-relaxed text-zinc-300">
                                {activityLine(entry)}
                            </p>
                        </article>
                    ))
                )}
            </div>

            {hasOlder && (
                <div className="border-t border-zinc-800/70 px-4 py-2 text-center">
                    <button
                        type="button"
                        onClick={onLoadOlder}
                        disabled={loadingOlder}
                        data-testid="agent-activity-load-older"
                        className="td-press rounded-md px-2 py-1 text-xs font-semibold text-amber-400 transition hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        {loadingOlder ? 'Loading…' : 'Load earlier activity'}
                    </button>
                </div>
            )}
        </section>
    );
}
