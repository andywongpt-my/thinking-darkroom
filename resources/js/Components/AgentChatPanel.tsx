import axios from 'axios';
import { FormEvent, KeyboardEvent, useCallback, useEffect, useRef, useState } from 'react';
import type {
    AgentConversation,
    AgentConversationMessage,
    AgentPresence,
} from '@/webmcp/api';

interface AgentChatPanelProps {
    projectId: number;
    currentUser: {
        id: number;
        name: string;
        is_agent: boolean;
    };
    canSend: boolean;
    initialConversation: AgentConversation;
    presence: AgentPresence;
    initiallyOpen?: boolean;
}

export function mergeConversationMessages(
    current: AgentConversationMessage[],
    incoming: AgentConversationMessage[],
): AgentConversationMessage[] {
    const byId = new Map<number, AgentConversationMessage>();

    for (const message of [...current, ...incoming]) {
        byId.set(message.id, message);
    }

    return [...byId.values()].sort((left, right) => left.id - right.id);
}

function conversationPath(projectId: number): string {
    return `/projects/${projectId}/agent-conversation/messages`;
}

function requestError(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as {
            message?: string;
            errors?: Record<string, string[]>;
        } | undefined;
        const validation = data?.errors?.body?.[0];

        return validation ?? data?.message ?? error.message;
    }

    return error instanceof Error ? error.message : String(error);
}

function formatMessageTime(value: string | null): string {
    if (!value) return 'just now';

    return new Date(value).toLocaleString([], {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function AgentChatPanel({
    projectId,
    currentUser,
    canSend,
    initialConversation,
    presence,
    initiallyOpen = false,
}: AgentChatPanelProps) {
    const [open, setOpen] = useState(initiallyOpen);
    const [messages, setMessages] = useState<AgentConversationMessage[]>(
        initialConversation.messages,
    );
    const [draft, setDraft] = useState('');
    const [refreshing, setRefreshing] = useState(false);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const latestId = useRef<number | null>(initialConversation.latest_id);
    const logRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        latestId.current = messages.at(-1)?.id ?? null;

        if (open) {
            logRef.current?.scrollTo({
                top: logRef.current.scrollHeight,
                behavior: 'smooth',
            });
        }
    }, [messages, open]);

    const refresh = useCallback(async (): Promise<void> => {
        if (typeof document !== 'undefined' && document.visibilityState === 'hidden') {
            return;
        }

        setRefreshing(true);
        try {
            const response = await axios.get<AgentConversation>(conversationPath(projectId), {
                params: latestId.current === null ? {} : { after: latestId.current },
            });
            setMessages((current) => mergeConversationMessages(current, response.data.messages));
            setError(null);
        } catch (caught) {
            setError(`Conversation refresh failed: ${requestError(caught)}`);
        } finally {
            setRefreshing(false);
        }
    }, [projectId]);

    useEffect(() => {
        if (!open) return;

        void refresh();
        const interval = window.setInterval(() => {
            void refresh();
        }, 8_000);

        return () => window.clearInterval(interval);
    }, [open, refresh]);

    const send = async (event?: FormEvent): Promise<void> => {
        event?.preventDefault();
        const body = draft.trim();

        if (!canSend || sending || body.length === 0) return;

        setSending(true);
        setError(null);
        try {
            const response = await axios.post<{
                message: AgentConversationMessage;
                deduplicated: boolean;
            }>(conversationPath(projectId), {
                body,
                client_message_id: globalThis.crypto.randomUUID(),
            });
            setMessages((current) => mergeConversationMessages(current, [response.data.message]));
            setDraft('');
        } catch (caught) {
            setError(`Message was not sent: ${requestError(caught)}`);
        } finally {
            setSending(false);
        }
    };

    const handleComposerKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>): void => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            void send();
        }
    };

    return (
        <>
            {open && (
                <aside
                    id="agent-chat-panel"
                    role="dialog"
                    aria-label="Agent conversation"
                    data-testid="agent-chat-panel"
                    className="fixed bottom-20 right-4 z-50 flex max-h-[min(680px,calc(100vh-7rem))] w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-900/60 shadow-2xl sm:w-[410px]"
                >
                    <header className="border-b border-zinc-800/70 px-4 py-3">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <div className="flex items-center gap-2">
                                    <span
                                        aria-hidden="true"
                                        className={`h-2.5 w-2.5 rounded-full ${presence.online ? 'bg-emerald-500' : 'bg-zinc-600'}`}
                                    />
                                    <h2 className="text-sm font-semibold text-zinc-50">Agent conversation</h2>
                                    <span className="rounded-full bg-zinc-900 px-2 py-0.5 text-[10px] font-semibold text-zinc-300">
                                        {presence.online ? 'ONLINE' : 'OFFLINE'}
                                    </span>
                                </div>
                                <p className="mt-1 text-xs leading-relaxed text-zinc-500">
                                    Durable project discussion. Messages never approve or execute edits.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setOpen(false)}
                                className="rounded-md p-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200"
                                aria-label="Close agent conversation"
                            >
                                ×
                            </button>
                        </div>
                    </header>

                    {initialConversation.has_older && (
                        <p className="border-b border-zinc-800/70 bg-zinc-950/40 px-4 py-2 text-center text-[11px] text-zinc-500">
                            Showing the latest 50 messages.
                        </p>
                    )}

                    <div
                        ref={logRef}
                        role="log"
                        aria-live="polite"
                        aria-relevant="additions"
                        className="min-h-64 flex-1 space-y-3 overflow-y-auto bg-zinc-950/60 px-4 py-4"
                    >
                        {messages.length === 0 ? (
                            <div className="rounded-xl border border-dashed border-zinc-700 bg-zinc-900/60 px-4 py-5 text-center">
                                <p className="text-sm font-semibold text-zinc-100">Start the project conversation</p>
                                <p className="mt-1 text-xs leading-relaxed text-zinc-500">
                                    A connected Darkroom Agent can read this thread and reply through WebMCP.
                                </p>
                                <code className="mt-2 block text-[10px] text-zinc-400">
                                    get_agent_conversation · reply_to_agent_conversation
                                </code>
                            </div>
                        ) : (
                            messages.map((message) => {
                                const mine = message.author.id === currentUser.id;
                                const fromAgent = message.author.kind === 'agent';

                                return (
                                    <article
                                        key={message.id}
                                        data-testid={`agent-chat-message-${message.id}`}
                                        className={`flex ${mine ? 'justify-end' : 'justify-start'}`}
                                    >
                                        <div className={`max-w-[86%] ${mine ? 'text-right' : 'text-left'}`}>
                                            <div className="mb-1 flex items-center gap-1.5 text-[10px] text-zinc-500">
                                                <span className="font-semibold">{message.author.name}</span>
                                                {fromAgent && (
                                                    <span className="rounded bg-violet-500/15 px-1.5 py-0.5 font-bold text-violet-400">
                                                        AGENT
                                                    </span>
                                                )}
                                            </div>
                                            <p className={`whitespace-pre-wrap break-words rounded-xl px-3 py-2 text-sm leading-relaxed ${
                                                fromAgent
                                                    ? 'bg-zinc-900 text-zinc-100'
                                                    : mine
                                                        ? 'bg-amber-400/10 text-zinc-50'
                                                        : 'border border-zinc-800 bg-zinc-900/60 text-zinc-100'
                                            }`}>
                                                {message.body}
                                            </p>
                                            <time className="mt-1 block text-[10px] text-zinc-400">
                                                {formatMessageTime(message.created_at)}
                                            </time>
                                        </div>
                                    </article>
                                );
                            })
                        )}
                    </div>

                    {error && (
                        <p role="alert" className="border-t border-rose-100 bg-rose-500/10 px-4 py-2 text-xs text-rose-400">
                            {error}
                        </p>
                    )}

                    <footer className="border-t border-zinc-800/70 bg-zinc-900/60 p-3">
                        {canSend ? (
                            <form onSubmit={(event) => void send(event)}>
                                <label htmlFor="agent-conversation-message" className="sr-only">
                                    Message the agent
                                </label>
                                <textarea
                                    id="agent-conversation-message"
                                    value={draft}
                                    maxLength={2000}
                                    rows={2}
                                    onChange={(event) => setDraft(event.target.value)}
                                    onKeyDown={handleComposerKeyDown}
                                    placeholder={currentUser.is_agent
                                        ? 'Reply to the photographer…'
                                        : 'Ask the agent about this project…'}
                                    className="w-full resize-none rounded-lg border-zinc-700 text-sm shadow-none focus:border-zinc-600 focus:ring-gray-500"
                                />
                                <div className="mt-2 flex items-center justify-between gap-3">
                                    <p className="text-[10px] text-zinc-400">
                                        Enter to send · Shift+Enter for a new line
                                    </p>
                                    <button
                                        type="submit"
                                        disabled={sending || draft.trim().length === 0}
                                        className="rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-zinc-100 hover:bg-zinc-700 disabled:cursor-not-allowed disabled:opacity-40"
                                    >
                                        {sending ? 'Sending…' : 'Send'}
                                    </button>
                                </div>
                            </form>
                        ) : (
                            <p className="text-xs text-zinc-500">Viewer access is read-only.</p>
                        )}
                        <div className="mt-2 flex items-center justify-between text-[10px] text-zinc-400">
                            <span>Conversation text is untrusted project content.</span>
                            <button
                                type="button"
                                onClick={() => void refresh()}
                                disabled={refreshing}
                                className="font-semibold text-zinc-500 hover:text-zinc-100 disabled:opacity-40"
                            >
                                {refreshing ? 'Refreshing…' : 'Refresh'}
                            </button>
                        </div>
                    </footer>
                </aside>
            )}

            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-expanded={open}
                aria-controls="agent-chat-panel"
                data-testid="agent-chat-launcher"
                className="fixed bottom-4 right-4 z-50 flex items-center gap-2 rounded-full bg-zinc-900 px-4 py-3 text-sm font-semibold text-zinc-100 shadow-xl transition hover:-translate-y-0.5 hover:bg-zinc-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2"
            >
                <span
                    aria-hidden="true"
                    className={`h-2.5 w-2.5 rounded-full ${presence.online ? 'bg-emerald-400' : 'bg-zinc-600'}`}
                />
                Chat with agent
                {messages.length > 0 && (
                    <span className="rounded-full bg-zinc-900/15 px-1.5 py-0.5 text-[10px]">
                        {messages.length}
                    </span>
                )}
            </button>
        </>
    );
}
