import { describe, expect, it } from 'vitest';
import { renderToString } from 'react-dom/server';
import AgentChatPanel, { mergeConversationMessages } from '@/Components/AgentChatPanel';
import type { AgentConversation, AgentPresence } from '@/webmcp/api';
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
    messages: [
        {
            id: 1,
            body: 'Which frame should lead the set?',
            client_message_id: null,
            author: { id: 1, name: 'Maya', kind: 'human' },
            created_at: '2026-08-31T00:00:00.000Z',
        },
        {
            id: 2,
            body: '<script>not executable</script> Frame 4 is the strongest keep.',
            client_message_id: null,
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
        expect(html).toContain('Agent conversation');
        expect(html).toContain('Durable project discussion');
        expect(html).toContain('Messages never approve or execute edits');
        expect(html).toContain('Which frame should lead the set?');
        expect(html).toContain('Darkroom Agent');
        expect(html).toContain('AGENT');
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
        expect(read?.description).toContain('untrusted member-authored content');
        expect(reply?.annotations?.readOnlyHint).toBe(false);
        expect(reply?.description).toContain('never approve, execute, alter photos');
        expect(reply?.inputSchema.required).toEqual(['body']);
    });
});
