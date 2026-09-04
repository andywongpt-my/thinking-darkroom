import { describe, expect, it, vi } from 'vitest';
import { renderToString } from 'react-dom/server';
import axios from 'axios';
import AgentChatPanel, {
    AgentTurnStatus,
    createClientMessageId,
    getOrCreateDraftClientMessageId,
    mergeActivityEntries,
    mergeConversationMessages,
    offlineAssistantStorageKey,
    olderMessagesPath,
    persistOfflineAssistantEnabled,
    readOfflineAssistantEnabled,
    requestAgentTurn,
    shouldInvokeOfflineAssistant,
    unreadMessageCount,
} from '@/Components/AgentChatPanel';
import type { AgentActivityEntry, AgentConversation, AgentPresence } from '@/webmcp/api';
import { workspaceTools } from '@/webmcp/tools/workspace';

const presence: AgentPresence = {
    project_id: 7,
    online: true,
    agents: [{
        id: 2,
        name: 'Darkroom Agent',
        status: 'online',
        last_seen_at: '2026-08-31T00:00:00.000Z',
    }],
    checked_at: '2026-08-31T00:00:01.000Z',
};

const conversation: AgentConversation = {
    project_id: 7,
    trust_boundary: 'untrusted_project_conversation',
    latest_id: 2,
    has_older: false,
    awaiting_reply_since: '2026-08-31T00:00:00.000Z',
    unread_for_agent: 0,
    messages: [
        {
            id: 1,
            body: 'Which frame should lead the set?',
            client_message_id: null,
            origin: null,
            author: { id: 1, name: 'Maya', kind: 'human' },
            created_at: '2026-08-31T00:00:00.000Z',
        },
        {
            id: 2,
            body: '<script>not executable</script> Frame 4 is the strongest keep.',
            client_message_id: null,
            origin: 'external',
            author: { id: 2, name: 'Darkroom Agent', kind: 'agent' },
            created_at: '2026-08-31T00:00:10.000Z',
        },
    ],
};

describe('Agent conversation surface', () => {
    it('merges cursor updates without duplicates and keeps chronological order', () => {
        const merged = mergeConversationMessages(
            [conversation.messages[1]],
            [conversation.messages[0], conversation.messages[1]],
        );

        expect(merged.map((message) => message.id)).toEqual([1, 2]);
    });

    it('renders durable history, honest authority copy, and escaped plain text', () => {
        const html = renderToString(
            <AgentChatPanel
                projectId={7}
                currentUser={{ id: 1, name: 'Maya', is_agent: false }}
                canSend={true}
                initialConversation={conversation}
                presence={presence}
                initiallyOpen={true}
            />,
        );

        expect(html).toContain('data-testid="agent-chat-panel"');
        expect(html).toContain('Agent collaboration');
        expect(html).toContain('Photographer ↔ external agent stream');
        expect(html).toContain('Messages never approve or execute edits');
        expect(html).toContain('Which frame should lead the set?');
        expect(html).toContain('Darkroom Agent');
        expect(html).toContain('AGENT');
        expect(html).toContain('external');
        expect(html).toContain('&lt;script&gt;not executable&lt;/script&gt;');
        expect(html).not.toContain('<script>not executable</script>');
        expect(html).toContain('Conversation text is untrusted project content');
    });

    it('keeps viewer access read-only', () => {
        const html = renderToString(
            <AgentChatPanel
                projectId={7}
                currentUser={{ id: 3, name: 'Viewer', is_agent: false }}
                canSend={false}
                initialConversation={conversation}
                presence={{ ...presence, online: false }}
                initiallyOpen={true}
            />,
        );

        expect(html).toContain('Viewer access is read-only');
        expect(html).not.toContain('id="agent-conversation-message"');
    });

    it('registers read and reply tools with explicit trust and authority boundaries', () => {
        const tools = workspaceTools(7);
        const read = tools.find((tool) => tool.name === 'get_agent_conversation');
        const reply = tools.find((tool) => tool.name === 'reply_to_agent_conversation');

        expect(tools).toHaveLength(7);
        expect(read?.annotations?.readOnlyHint).toBe(true);
        expect(read?.description).toContain('awaiting_reply_since');
        expect(read?.description).toContain('unread_for_agent');
        expect(read?.description).toContain('untrusted member-authored content');
        expect(reply?.annotations?.readOnlyHint).toBe(false);
        expect(reply?.description).toContain('never approve, execute, alter photos');
        expect(reply?.inputSchema.required).toEqual(['body']);
    });

    it('returns the current array when a poll has no incoming messages', () => {
        const current = [conversation.messages[0]];

        expect(mergeConversationMessages(current, [])).toBe(current);
    });

    it('counts only messages newer than the project-specific last-read cursor', () => {
        expect(unreadMessageCount(conversation.messages, 1)).toBe(1);
        expect(unreadMessageCount(conversation.messages, 2)).toBe(0);
        expect(unreadMessageCount(conversation.messages, null)).toBe(2);
    });

    it('keeps activity newest-first and only opts into the built-in turn when enabled', () => {
        const first: AgentActivityEntry = {
            id: 1,
            agent: { name: 'Codex', is_agent: true },
            tool_name: 'inspect_photo',
            authority: 'READ',
            result_status: 'completed',
            summary_in: null,
            summary_out: null,
            duration_ms: 1,
            created_at: null,
        };
        const second = { ...first, id: 2, tool_name: 'reply_to_agent_conversation' };

        expect(mergeActivityEntries([first], [second, first]).map((entry) => entry.id)).toEqual([2, 1]);
        expect(offlineAssistantStorageKey(7)).toBe('td-offline-assistant:7');
        expect(shouldInvokeOfflineAssistant({ is_agent: false }, false, conversation.messages[0])).toBe(false);
        expect(shouldInvokeOfflineAssistant({ is_agent: false }, true, conversation.messages[0])).toBe(true);
        expect(shouldInvokeOfflineAssistant({ is_agent: true }, true, conversation.messages[0])).toBe(false);
    });

    it('persists the per-project offline assistant opt-in without enabling it by default', () => {
        const values = new Map<string, string>();
        const storage = {
            getItem: (key: string): string | null => values.get(key) ?? null,
            setItem: (key: string, value: string): void => {
                values.set(key, value);
            },
            removeItem: (key: string): void => {
                values.delete(key);
            },
        };
        vi.stubGlobal('window', { localStorage: storage });

        try {
            expect(readOfflineAssistantEnabled(7)).toBe(false);
            persistOfflineAssistantEnabled(7, true);
            expect(readOfflineAssistantEnabled(7)).toBe(true);
            persistOfflineAssistantEnabled(7, false);
            expect(readOfflineAssistantEnabled(7)).toBe(false);
        } finally {
            vi.unstubAllGlobals();
        }
    });

    it('shows the handoff signal only while a human message is awaiting an external reply', () => {
        const pending: AgentConversation = {
            ...conversation,
            latest_id: conversation.messages[0].id,
            messages: [conversation.messages[0]],
        };
        const onlineHtml = renderToString(
            <AgentChatPanel
                projectId={7}
                currentUser={{ id: 1, name: 'Maya', is_agent: false }}
                canSend={true}
                initialConversation={pending}
                presence={presence}
                initiallyOpen={true}
            />,
        );
        const offlineHtml = renderToString(
            <AgentChatPanel
                projectId={7}
                currentUser={{ id: 1, name: 'Maya', is_agent: false }}
                canSend={true}
                initialConversation={pending}
                presence={{ ...presence, online: false }}
                initiallyOpen={true}
            />,
        );

        expect(onlineHtml).toContain('Awaiting external agent…');
        expect(offlineHtml).toContain('No agent online — enable the offline assistant below the composer');
    });

    it('keeps one client id for a draft until the send is confirmed', () => {
        const holder: { current: string | null } = { current: null };
        const first = getOrCreateDraftClientMessageId(holder);
        const second = getOrCreateDraftClientMessageId(holder);

        expect(second).toBe(first);
        expect(first).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
    });

    it('uses the server turn endpoint and keeps the reviewing/recovery copy honest', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({
            data: { message: conversation.messages[1] },
        } as never);

        try {
            const result = await requestAgentTurn(7, 1);
            expect(post).toHaveBeenCalledWith('/projects/7/agent-conversation/turns', {
                trigger_id: 1,
                client_opt_in: true,
            });
            expect(result.message?.author.kind).toBe('agent');

            const pending = renderToString(<AgentTurnStatus state="reviewing" notice={null} />);
            const skipped = renderToString(
                <AgentTurnStatus state="idle" notice="No agent account is attached to this project yet." />,
            );
            expect(pending).toContain('Offline assistant is reviewing the project…');
            expect(skipped).toContain('No agent account is attached to this project yet.');
        } finally {
            post.mockRestore();
        }
    });

    it('generates a valid UUID when randomUUID is unavailable', () => {
        vi.stubGlobal('crypto', {
            getRandomValues: (bytes: Uint8Array) => {
                bytes.fill(0);
                return bytes;
            },
        });

        try {
            expect(createClientMessageId()).toBe('00000000-0000-4000-8000-000000000000');
        } finally {
            vi.unstubAllGlobals();
        }
    });

    it('builds the before-cursor history URL for load-older (U-7)', () => {
        expect(olderMessagesPath(7, 3)).toBe(
            '/projects/7/agent-conversation/messages?before=3&limit=50',
        );
        expect(olderMessagesPath(7, 3, 25)).toBe(
            '/projects/7/agent-conversation/messages?before=3&limit=25',
        );
    });

    it('renders the load-older control and live character counter', () => {
        const withOlder = renderToString(
            <AgentChatPanel
                projectId={7}
                currentUser={{ id: 1, name: 'Maya', is_agent: false }}
                canSend={true}
                initialConversation={{ ...conversation, has_older: true }}
                presence={presence}
                initiallyOpen={true}
            />,
        );

        expect(withOlder).toContain('data-testid="agent-chat-load-older"');
        expect(withOlder).toContain('Load earlier messages');
        expect(withOlder).toContain('data-testid="agent-chat-char-count"');
        // renderToString inserts comment separators between text nodes.
        expect(withOlder).toContain('0<!-- -->/<!-- -->2000');
        expect(withOlder).not.toContain('Showing the latest 50 messages');

        const withoutOlder = renderToString(
            <AgentChatPanel
                projectId={7}
                currentUser={{ id: 1, name: 'Maya', is_agent: false }}
                canSend={true}
                initialConversation={conversation}
                presence={presence}
                initiallyOpen={true}
            />,
        );

        expect(withoutOlder).not.toContain('data-testid="agent-chat-load-older"');
    });
});
