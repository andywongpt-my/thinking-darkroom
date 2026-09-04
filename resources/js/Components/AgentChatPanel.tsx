import axios from 'axios';
import { FormEvent, KeyboardEvent, useCallback, useEffect, useRef, useState } from 'react';
import AgentActivityFeed from './AgentActivityFeed';
import type {
    AgentActivityEntry,
    AgentActivityResponse,
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
    /** U-4/U-5: parent-requested open pulse (e.g. presence strip click). */
    openSignal?: number;
    /**
     * Demo-chain: fired after an agent turn completes (success, skip, or
     * failure) so the parent can reload page props — a cull-intent turn may
     * have created a pending proposal the photographer must see.
     */
    onAgentTurnComplete?: () => void;
}

export function mergeConversationMessages(
    current: AgentConversationMessage[],
    incoming: AgentConversationMessage[],
): AgentConversationMessage[] {
    if (incoming.length === 0) return current;

    const byId = new Map<number, AgentConversationMessage>();

    for (const message of [...current, ...incoming]) {
        byId.set(message.id, message);
    }

    return [...byId.values()].sort((left, right) => left.id - right.id);
}

export function unreadMessageCount(
    messages: AgentConversationMessage[],
    lastReadId: number | null,
): number {
    return messages.filter((message) => lastReadId === null || message.id > lastReadId).length;
}

export function createClientMessageId(): string {
    const cryptoApi = globalThis.crypto;

    try {
        if (typeof cryptoApi?.randomUUID === 'function') {
            return cryptoApi.randomUUID();
        }
    } catch {
        // Fall through to the standards-compatible UUID generator below.
    }

    const bytes = new Uint8Array(16);
    try {
        if (typeof cryptoApi?.getRandomValues === 'function') {
            cryptoApi.getRandomValues(bytes);
        } else {
            for (let index = 0; index < bytes.length; index += 1) {
                bytes[index] = Math.floor(Math.random() * 256);
            }
        }
    } catch {
        for (let index = 0; index < bytes.length; index += 1) {
            bytes[index] = Math.floor(Math.random() * 256);
        }
    }

    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function getOrCreateDraftClientMessageId(holder: { current: string | null }): string {
    if (holder.current === null) {
        holder.current = createClientMessageId();
    }

    return holder.current;
}

export function agentChatLastReadStorageKey(projectId: number): string {
    return `agent-chat:last-read-id:${projectId}`;
}

export function offlineAssistantStorageKey(projectId: number): string {
    return `td-offline-assistant:${projectId}`;
}

export function readOfflineAssistantEnabled(projectId: number): boolean {
    if (typeof window === 'undefined') return false;

    try {
        return window.localStorage.getItem(offlineAssistantStorageKey(projectId)) === 'true';
    } catch {
        return false;
    }
}

export function persistOfflineAssistantEnabled(projectId: number, value: boolean): void {
    if (typeof window === 'undefined') return;

    try {
        const key = offlineAssistantStorageKey(projectId);
        if (value) {
            window.localStorage.setItem(key, 'true');
        } else {
            window.localStorage.removeItem(key);
        }
    } catch {
        // Storage can be unavailable in privacy-restricted browser contexts.
    }
}

export function shouldInvokeOfflineAssistant(
    currentUser: { is_agent: boolean },
    offlineAssistantEnabled: boolean,
    message: AgentConversationMessage,
): boolean {
    return offlineAssistantEnabled
        && !currentUser.is_agent
        && message.author.kind !== 'agent';
}

export function mergeActivityEntries(
    current: AgentActivityEntry[],
    incoming: AgentActivityEntry[],
): AgentActivityEntry[] {
    if (incoming.length === 0) return current;

    const byId = new Map<number, AgentActivityEntry>();

    for (const entry of [...current, ...incoming]) {
        byId.set(entry.id, entry);
    }

    return [...byId.values()].sort((left, right) => right.id - left.id);
}

export interface AgentTurnResponse {
    message: AgentConversationMessage | null;
    skipped?: string;
}

export function requestAgentTurn(projectId: number, triggerId: number): Promise<AgentTurnResponse> {
    return axios
        .post<AgentTurnResponse>(`/projects/${projectId}/agent-conversation/turns`, {
            trigger_id: triggerId,
            client_opt_in: true,
        })
        .then((response) => response.data);
}

export function AgentTurnStatus({
    state,
    notice,
}: {
    state: 'idle' | 'reviewing';
    notice: string | null;
}) {
    if (state === 'reviewing') {
        return (
            <p
                role="status"
                aria-live="polite"
                data-testid="agent-turn-status"
                className="mb-2 text-xs text-zinc-500"
            >
                Offline assistant is reviewing the project…
            </p>
        );
    }

    if (notice === null) return null;

    return (
        <p
            role="status"
            aria-live="polite"
            data-testid="agent-turn-status"
            className="mb-2 text-xs text-zinc-500"
        >
            {notice}
        </p>
    );
}

function conversationPath(projectId: number): string {
    return `/projects/${projectId}/agent-conversation/messages`;
}

function activityPath(projectId: number): string {
    return `/projects/${projectId}/agent-activity`;
}

export function olderMessagesPath(projectId: number, beforeId: number, limit = 50): string {
    return `/projects/${projectId}/agent-conversation/messages?before=${beforeId}&limit=${limit}`;
}

export const COMPOSER_MAX_LENGTH = 2000;

function readLastReadId(projectId: number, fallback: number | null): number | null {
    if (typeof window === 'undefined') return fallback;

    try {
        const stored = window.localStorage.getItem(agentChatLastReadStorageKey(projectId));
        if (stored === null) return fallback;

        const parsed = Number(stored);
        return Number.isInteger(parsed) && parsed >= 0 ? parsed : fallback;
    } catch {
        return fallback;
    }
}

function persistLastReadId(projectId: number, value: number | null): void {
    if (typeof window === 'undefined') return;

    try {
        const key = agentChatLastReadStorageKey(projectId);
        if (value === null) {
            window.localStorage.removeItem(key);
        } else {
            window.localStorage.setItem(key, String(value));
        }
    } catch {
        // Storage can be unavailable in privacy-restricted browser contexts.
    }
}

function skippedTurnMessage(reason: string): string {
    if (reason === 'no_agent_member') {
        return 'No agent account is attached to this project yet.';
    }

    if (reason === 'non_human_trigger') {
        return 'This message does not need another agent response.';
    }

    return `Agent turn skipped: ${reason.replaceAll('_', ' ')}.`;
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
    openSignal = 0,
    onAgentTurnComplete,
}: AgentChatPanelProps) {
    const [open, setOpen] = useState(initiallyOpen);
    const [messages, setMessages] = useState<AgentConversationMessage[]>(
        initialConversation.messages,
    );
    const [draft, setDraft] = useState('');
    const [refreshing, setRefreshing] = useState(false);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [lastReadId, setLastReadId] = useState<number | null>(() =>
        readLastReadId(projectId, initialConversation.latest_id),
    );
    const [liveAnnouncement, setLiveAnnouncement] = useState('');
    const [agentTurnState, setAgentTurnState] = useState<'idle' | 'reviewing'>('idle');
    const [agentTurnNotice, setAgentTurnNotice] = useState<string | null>(null);
    const [hasOlder, setHasOlder] = useState(initialConversation.has_older);
    const [loadingOlder, setLoadingOlder] = useState(false);
    const [offlineAssistantEnabled, setOfflineAssistantEnabled] = useState(() =>
        readOfflineAssistantEnabled(projectId),
    );
    const [activeTab, setActiveTab] = useState<'conversation' | 'activity'>('conversation');
    const [activity, setActivity] = useState<AgentActivityEntry[]>([]);
    const [activityRefreshing, setActivityRefreshing] = useState(false);
    const [activityError, setActivityError] = useState<string | null>(null);
    const [activityHasOlder, setActivityHasOlder] = useState(false);
    const [activityLoadingOlder, setActivityLoadingOlder] = useState(false);
    const [activityFilter, setActivityFilter] = useState('');
    const latestId = useRef<number | null>(initialConversation.latest_id);
    const lastMessageId = useRef<number | null>(initialConversation.latest_id);
    const draftMessageIdRef = useRef<string | null>(null);
    const sendInFlightRef = useRef(false);
    const refreshInFlightRef = useRef(false);
    const loadingOlderRef = useRef(false);
    const activityLatestId = useRef<number | null>(null);
    const activityRefreshInFlightRef = useRef(false);
    const activityLoadingOlderRef = useRef(false);
    const logRef = useRef<HTMLDivElement>(null);
    const mountedProjectId = useRef(projectId);

    const markAllRead = useCallback((value: number | null): void => {
        setLastReadId(value);
        persistLastReadId(projectId, value);
    }, [projectId]);

    useEffect(() => {
        const nextLastMessage = messages.at(-1);
        const nextLatestId = nextLastMessage?.id ?? null;
        const hasNewLastMessage = nextLatestId !== lastMessageId.current;
        latestId.current = nextLatestId;

        if (hasNewLastMessage) {
            if (open) {
                logRef.current?.scrollTo({
                    top: logRef.current.scrollHeight,
                    behavior: 'smooth',
                });
                markAllRead(nextLatestId);
                setLiveAnnouncement('');
            } else if (nextLastMessage !== undefined) {
                setLiveAnnouncement(`New message from ${nextLastMessage.author.name}: ${nextLastMessage.body}`);
            }

            lastMessageId.current = nextLatestId;
        }
    }, [messages, open, markAllRead]);

    useEffect(() => {
        if (!open) return;

        // Opening the drawer reveals the latest messages: the log mounts at
        // scrollTop 0, so an explicit scroll is required even when nothing
        // new arrived while the panel was closed.
        logRef.current?.scrollTo({
            top: logRef.current.scrollHeight,
        });
        markAllRead(latestId.current);
        setLiveAnnouncement('');
    }, [open, markAllRead]);

    useEffect(() => {
        if (mountedProjectId.current === projectId) return;

        mountedProjectId.current = projectId;
        const nextLastReadId = readLastReadId(projectId, initialConversation.latest_id);
        setOpen(initiallyOpen);
        setMessages(initialConversation.messages);
        setDraft('');
        setRefreshing(false);
        setSending(false);
        setError(null);
        setLastReadId(nextLastReadId);
        setLiveAnnouncement('');
        setAgentTurnState('idle');
        setAgentTurnNotice(null);
        setHasOlder(initialConversation.has_older);
        setLoadingOlder(false);
        setOfflineAssistantEnabled(readOfflineAssistantEnabled(projectId));
        setActiveTab('conversation');
        setActivity([]);
        setActivityRefreshing(false);
        setActivityError(null);
        setActivityHasOlder(false);
        setActivityLoadingOlder(false);
        setActivityFilter('');
        latestId.current = initialConversation.latest_id;
        lastMessageId.current = initialConversation.latest_id;
        draftMessageIdRef.current = null;
        sendInFlightRef.current = false;
        refreshInFlightRef.current = false;
        activityLatestId.current = null;
        activityRefreshInFlightRef.current = false;
        activityLoadingOlderRef.current = false;
    }, [projectId, initialConversation, initiallyOpen]);

    const refresh = useCallback(async (): Promise<void> => {
        if (refreshInFlightRef.current) return;
        if (typeof document !== 'undefined' && document.visibilityState === 'hidden') {
            return;
        }

        refreshInFlightRef.current = true;
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
            refreshInFlightRef.current = false;
            setRefreshing(false);
        }
    }, [projectId]);

    const refreshActivity = useCallback(async (): Promise<void> => {
        if (activityRefreshInFlightRef.current) return;
        if (typeof document !== 'undefined' && document.visibilityState === 'hidden') {
            return;
        }

        const hadCursor = activityLatestId.current !== null;
        activityRefreshInFlightRef.current = true;
        setActivityRefreshing(true);
        try {
            const response = await axios.get<AgentActivityResponse>(activityPath(projectId), {
                params: activityLatestId.current === null ? {} : { after: activityLatestId.current },
            });
            setActivity((current) => mergeActivityEntries(current, response.data.activity));
            if (!hadCursor) {
                setActivityHasOlder(response.data.has_older);
            }
            activityLatestId.current = response.data.latest_id;
            setActivityError(null);
        } catch (caught) {
            setActivityError(`Activity refresh failed: ${requestError(caught)}`);
        } finally {
            activityRefreshInFlightRef.current = false;
            setActivityRefreshing(false);
        }
    }, [projectId]);

    useEffect(() => {
        void refresh();
        void refreshActivity();
        const interval = window.setInterval(() => {
            void refresh();
            void refreshActivity();
        }, open ? 8_000 : 30_000);

        return () => window.clearInterval(interval);
    }, [open, refresh, refreshActivity]);

    const loadOlder = useCallback(async (): Promise<void> => {
        if (loadingOlderRef.current || messages.length === 0) return;

        loadingOlderRef.current = true;
        setLoadingOlder(true);
        try {
            const oldestId = messages[0].id;
            const response = await axios.get<AgentConversation>(
                olderMessagesPath(projectId, oldestId),
            );
            const older = response.data.messages;
            if (older.length > 0) {
                // Prepend without scrolling: the log's existing content stays
                // anchored, and new rows extend upward (U-7).
                setMessages((current) => mergeConversationMessages(current, older));
            }
            setHasOlder(response.data.has_older);
            setError(null);
        } catch (caught) {
            setError(`Loading history failed: ${requestError(caught)}`);
        } finally {
            loadingOlderRef.current = false;
            setLoadingOlder(false);
        }
    }, [projectId, messages]);

    const loadOlderActivity = useCallback(async (): Promise<void> => {
        if (activityLoadingOlderRef.current || activity.length === 0) return;

        activityLoadingOlderRef.current = true;
        setActivityLoadingOlder(true);
        try {
            const oldestId = activity.at(-1)?.id;
            if (oldestId === undefined) return;

            const response = await axios.get<AgentActivityResponse>(activityPath(projectId), {
                params: { before: oldestId, limit: 50 },
            });
            setActivity((current) => mergeActivityEntries(current, response.data.activity));
            setActivityHasOlder(response.data.has_older);
            setActivityError(null);
        } catch (caught) {
            setActivityError(`Loading activity history failed: ${requestError(caught)}`);
        } finally {
            activityLoadingOlderRef.current = false;
            setActivityLoadingOlder(false);
        }
    }, [projectId, activity]);

    // U-8: Escape closes the drawer so keyboard users are not trapped in the
    // dialog; the launcher keeps focus afterwards.
    useEffect(() => {
        if (!open) return;

        const handleKeyDown = (event: globalThis.KeyboardEvent): void => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [open]);

    // U-4/U-5: a parent pulse (counter bump) opens the drawer so workspace
    // affordances like the presence strip can route into the conversation.
    useEffect(() => {
        if (openSignal > 0) {
            setOpen(true);
        }
    }, [openSignal, projectId]);

    const invokeAgentTurn = useCallback(async (triggerId: number): Promise<void> => {
        setAgentTurnState('reviewing');
        setAgentTurnNotice(null);

        try {
            const result = await requestAgentTurn(projectId, triggerId);
            if (result.skipped) {
                setAgentTurnNotice(skippedTurnMessage(result.skipped));
            } else if (result.message) {
                setMessages((current) => mergeConversationMessages(current, [result.message as AgentConversationMessage]));
            }
        } catch (caught) {
            setAgentTurnNotice(`Agent review failed: ${requestError(caught)}`);
        } finally {
            await Promise.all([refresh(), refreshActivity()]);
            setAgentTurnState('idle');
            onAgentTurnComplete?.();
        }
    }, [projectId, refresh, refreshActivity, onAgentTurnComplete]);

    const send = async (event?: FormEvent): Promise<void> => {
        event?.preventDefault();
        const body = draft.trim();

        if (!canSend || sending || sendInFlightRef.current || body.length === 0) return;

        sendInFlightRef.current = true;
        setSending(true);
        setError(null);
        const clientMessageId = getOrCreateDraftClientMessageId(draftMessageIdRef);
        try {
            const response = await axios.post<{
                message: AgentConversationMessage;
                deduplicated: boolean;
            }>(conversationPath(projectId), {
                body,
                client_message_id: clientMessageId,
            });
            setMessages((current) => mergeConversationMessages(current, [response.data.message]));
            setDraft('');
            draftMessageIdRef.current = null;

            if (shouldInvokeOfflineAssistant(currentUser, offlineAssistantEnabled, response.data.message)) {
                void invokeAgentTurn(response.data.message.id);
            }
        } catch (caught) {
            setError(`Message was not sent: ${requestError(caught)}`);
        } finally {
            sendInFlightRef.current = false;
            setSending(false);
        }
    };

    const handleComposerKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>): void => {
        if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
            event.preventDefault();
            void send();
        }
    };

    const unreadCount = unreadMessageCount(messages, lastReadId);
    const latestMessage = messages.at(-1);
    const handoffNotice = !currentUser.is_agent
        && !offlineAssistantEnabled
        && latestMessage?.author.kind === 'human'
        ? presence.online
            ? 'Awaiting external agent…'
            : 'No agent online — enable the offline assistant below the composer'
        : null;

    return (
        <>
            <div
                role="status"
                aria-live="polite"
                aria-atomic="true"
                data-testid="agent-chat-live-region"
                className="sr-only"
            >
                {!open ? liveAnnouncement : ''}
            </div>
            {open && (
                <aside
                    id="agent-chat-panel"
                    role="dialog"
                    aria-label="Agent conversation"
                    data-testid="agent-chat-panel"
                    className="td-slide-up fixed bottom-20 right-4 z-50 flex max-h-[min(680px,calc(100vh-7rem))] w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-2xl border border-zinc-800 bg-zinc-900/60 shadow-2xl sm:w-[410px]"
                >
                    <header className="border-b border-zinc-800/70 px-4 py-3">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <div className="flex items-center gap-2">
                                    <span
                                        aria-hidden="true"
                                        className={`h-2.5 w-2.5 rounded-full ${presence.online ? 'bg-emerald-500' : 'bg-zinc-600'}`}
                                    />
                                    <h2 className="text-sm font-semibold text-zinc-50">Agent collaboration</h2>
                                    <span className="rounded-full bg-zinc-900 px-2 py-0.5 text-xs font-semibold text-zinc-300">
                                        {presence.online ? 'ONLINE' : 'OFFLINE'}
                                    </span>
                                </div>
                                <p className="mt-1 text-xs leading-relaxed text-zinc-500">
                                    Photographer ↔ external agent stream. Messages never approve or execute edits.
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

                    <nav
                        role="tablist"
                        aria-label="Agent collaboration views"
                        className="flex border-b border-zinc-800/70 bg-zinc-900/40 px-3 pt-2"
                    >
                        <button
                            type="button"
                            role="tab"
                            aria-selected={activeTab === 'conversation'}
                            aria-controls="agent-conversation-view"
                            data-testid="agent-chat-tab-conversation"
                            onClick={() => setActiveTab('conversation')}
                            className={`flex-1 rounded-t-md px-3 py-2 text-xs font-semibold transition ${activeTab === 'conversation' ? 'border-b-2 border-amber-400 text-amber-300' : 'text-zinc-500 hover:text-zinc-200'}`}
                        >
                            Conversation
                        </button>
                        <button
                            type="button"
                            role="tab"
                            aria-selected={activeTab === 'activity'}
                            aria-controls="agent-activity-view"
                            data-testid="agent-chat-tab-activity"
                            onClick={() => setActiveTab('activity')}
                            className={`flex-1 rounded-t-md px-3 py-2 text-xs font-semibold transition ${activeTab === 'activity' ? 'border-b-2 border-amber-400 text-amber-300' : 'text-zinc-500 hover:text-zinc-200'}`}
                        >
                            Activity
                        </button>
                    </nav>

                    {activeTab === 'conversation' && hasOlder && (
                        <div className="border-b border-zinc-800/70 bg-zinc-950/40 px-4 py-2 text-center">
                            <button
                                type="button"
                                onClick={() => void loadOlder()}
                                disabled={loadingOlder}
                                data-testid="agent-chat-load-older"
                                className="td-press rounded-md px-2 py-1 text-xs font-semibold text-amber-400 transition hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-40"
                            >
                                {loadingOlder ? 'Loading…' : 'Load earlier messages'}
                            </button>
                        </div>
                    )}

                    {activeTab === 'conversation' ? (
                        <div
                            id="agent-conversation-view"
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
                                <code className="mt-2 block text-xs text-zinc-400">
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
                                        className={`td-pop-in flex ${mine ? 'justify-end' : 'justify-start'}`}
                                    >
                                        <div className={`max-w-[86%] ${mine ? 'text-right' : 'text-left'}`}>
                                            <div className="mb-1 flex items-center gap-1.5 text-xs text-zinc-500">
                                                <span className="font-semibold">{message.author.name}</span>
                                                {fromAgent && (
                                                    <span className="rounded bg-violet-500/15 px-1.5 py-0.5 font-bold text-violet-300">
                                                        AGENT
                                                    </span>
                                                )}
                                                {fromAgent && message.origin === 'agent_turn' && (
                                                    <span className="rounded bg-zinc-800 px-1.5 py-0.5 font-semibold text-zinc-400">
                                                        built-in
                                                    </span>
                                                )}
                                                {fromAgent && message.origin === 'external' && (
                                                    <span className="rounded bg-emerald-500/15 px-1.5 py-0.5 font-semibold text-emerald-300">
                                                        external
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
                                            <time className="mt-1 block text-xs text-zinc-400">
                                                {formatMessageTime(message.created_at)}
                                            </time>
                                        </div>
                                    </article>
                                );
                            })
                        )}
                    </div>
                    ) : (
                        <AgentActivityFeed
                            entries={activity}
                            filter={activityFilter}
                            loading={activityRefreshing}
                            error={activityError}
                            hasOlder={activityHasOlder}
                            loadingOlder={activityLoadingOlder}
                            onFilterChange={setActivityFilter}
                            onLoadOlder={() => void loadOlderActivity()}
                        />
                    )}

                    {error && (
                        <p role="alert" className="td-fade-in border-t border-rose-500/30 bg-rose-500/10 px-4 py-2 text-xs text-rose-400">
                            {error}
                        </p>
                    )}

                    <footer className="border-t border-zinc-800/70 bg-zinc-900/60 p-3">
                        <AgentTurnStatus state={agentTurnState} notice={agentTurnNotice} />
                        {handoffNotice !== null && (
                            <p
                                role="status"
                                aria-live="polite"
                                data-testid="agent-handoff-status"
                                className="mb-2 text-xs text-zinc-500"
                            >
                                {handoffNotice}
                            </p>
                        )}
                        {canSend ? (
                            <form onSubmit={(event) => void send(event)}>
                                {!currentUser.is_agent && (
                                    <div className="mb-2 flex items-center justify-between gap-2">
                                        <label className="flex cursor-pointer items-center gap-2 text-xs font-semibold text-zinc-300">
                                            <input
                                                type="checkbox"
                                                checked={offlineAssistantEnabled}
                                                data-testid="offline-assistant-toggle"
                                                onChange={(event) => {
                                                    const enabled = event.target.checked;
                                                    setOfflineAssistantEnabled(enabled);
                                                    persistOfflineAssistantEnabled(projectId, enabled);
                                                    setAgentTurnNotice(null);
                                                }}
                                                className="rounded border-zinc-600 bg-zinc-950 text-amber-400 focus:ring-amber-400/60"
                                            />
                                            Offline assistant
                                        </label>
                                        <span className="text-[11px] text-zinc-500">Built-in fallback · off by default</span>
                                    </div>
                                )}
                                <label htmlFor="agent-conversation-message" className="sr-only">
                                    Message the external agent
                                </label>
                                <textarea
                                    id="agent-conversation-message"
                                    value={draft}
                                    maxLength={COMPOSER_MAX_LENGTH}
                                    rows={2}
                                    onChange={(event) => setDraft(event.target.value)}
                                    onKeyDown={handleComposerKeyDown}
                                    placeholder={currentUser.is_agent
                                        ? 'Reply to the photographer…'
                                        : 'Ask the external agent about this project…'}
                                    className="w-full resize-none rounded-lg border-zinc-700 text-sm text-zinc-100 shadow-none transition focus:border-amber-400/60 focus:ring-amber-400/60"
                                />
                                <div className="mt-2 flex items-center justify-between gap-3">
                                    <p className="text-xs text-zinc-400">
                                        Enter to send · Shift+Enter for a new line
                                    </p>
                                    <p
                                        aria-live="off"
                                        data-testid="agent-chat-char-count"
                                        className={`text-xs tabular-nums ${draft.length >= COMPOSER_MAX_LENGTH ? 'text-rose-400' : 'text-zinc-500'}`}
                                    >
                                        {draft.length}/{COMPOSER_MAX_LENGTH}
                                    </p>
                                    <button
                                        type="submit"
                                        disabled={sending || draft.trim().length === 0}
                                        className="rounded-lg bg-amber-400 px-3 py-1.5 text-xs font-semibold text-zinc-950 hover:bg-amber-300 disabled:cursor-not-allowed disabled:opacity-40"
                                    >
                                        {sending ? 'Sending…' : 'Send'}
                                    </button>
                                </div>
                            </form>
                        ) : (
                            <p className="text-xs text-zinc-500">Viewer access is read-only.</p>
                        )}
                        <div className="mt-2 flex items-center justify-between text-xs text-zinc-400">
                            <span>
                                {activeTab === 'conversation'
                                    ? 'Conversation text is untrusted project content.'
                                    : 'Activity summaries are untrusted agent-authored content.'}
                            </span>
                            <button
                                type="button"
                                onClick={() => {
                                    void refresh();
                                    void refreshActivity();
                                }}
                                disabled={refreshing || activityRefreshing}
                                className="font-semibold text-zinc-500 hover:text-zinc-100 disabled:opacity-40"
                            >
                                {refreshing || activityRefreshing ? 'Refreshing…' : 'Refresh'}
                            </button>
                        </div>
                    </footer>
                </aside>
            )}

            <button
                type="button"
                onClick={() => {
                    if (!open) {
                        markAllRead(latestId.current);
                    }
                    setOpen((value) => !value);
                }}
                aria-expanded={open}
                aria-controls="agent-chat-panel"
                data-testid="agent-chat-launcher"
                className="td-press fixed bottom-4 right-4 z-50 flex items-center gap-2 rounded-full bg-zinc-100 px-4 py-3 text-sm font-semibold text-zinc-950 shadow-xl transition hover:-translate-y-0.5 hover:bg-zinc-200 focus:outline-none focus:ring-2 focus:ring-amber-400/60 focus:ring-offset-2"
            >
                <span
                    aria-hidden="true"
                    className={`h-2.5 w-2.5 rounded-full ${presence.online ? 'bg-emerald-400' : 'bg-zinc-600'}`}
                />
                Chat with agent
                {unreadCount > 0 && (
                    <span className="rounded-full bg-zinc-900/15 px-1.5 py-0.5 text-xs">
                        {unreadCount}
                    </span>
                )}
            </button>
        </>
    );
}
