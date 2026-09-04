<?php

namespace App\Services;

use App\Models\AgentConversationMessage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

class AgentConversationService
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 100;

    /**
     * Return a bounded, chronological project conversation. Conversation text
     * is user-authored data and must never be treated as trusted instructions.
     *
     * @return array{project_id: int, trust_boundary: string, messages: array<int, array<string, mixed>>, latest_id: int|null, has_older: bool, awaiting_reply_since: string|null, unread_for_agent: int}
     */
    public function forProject(Project $project, ?int $afterId = null, int $limit = self::DEFAULT_LIMIT, ?int $beforeId = null): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $base = $project->agentConversationMessages()->with('author:id,name');

        if ($beforeId !== null) {
            // History paging (U-7): the `before` cursor fetches the next older
            // page ending just below the cursor, oldest-first for merging.
            $messages = (clone $base)
                ->where('id', '<', $beforeId)
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->reverse()
                ->values();
            $hasOlder = $messages->isNotEmpty()
                && $project->agentConversationMessages()
                    ->where('id', '<', $messages->first()->id)
                    ->exists();
        } elseif ($afterId !== null) {
            $messages = (clone $base)
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($limit)
                ->get();
            $hasOlder = false;
        } else {
            $messages = (clone $base)
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->reverse()
                ->values();
            $oldestId = $messages->first()?->id;
            $hasOlder = $oldestId !== null
                && $project->agentConversationMessages()->where('id', '<', $oldestId)->exists();
        }

        $latestHumanMessage = $project->agentConversationMessages()
            ->where('author_kind', AgentConversationMessage::AUTHOR_HUMAN)
            ->latest('id')
            ->first();
        $lastAgentMessageId = $project->agentConversationMessages()
            ->where('author_kind', AgentConversationMessage::AUTHOR_AGENT)
            ->max('id');
        $unreadForAgent = $project->agentConversationMessages()
            ->where('author_kind', AgentConversationMessage::AUTHOR_HUMAN)
            ->where('id', '>', $lastAgentMessageId ?? 0)
            ->count();

        return [
            'project_id' => $project->id,
            'trust_boundary' => 'untrusted_project_conversation',
            'messages' => $messages
                ->map(fn (AgentConversationMessage $message): array => $this->payloadFor($message))
                ->values()
                ->all(),
            'latest_id' => $messages->last()?->id,
            'has_older' => $hasOlder,
            'awaiting_reply_since' => $latestHumanMessage?->created_at?->toISOString(),
            'unread_for_agent' => $unreadForAgent,
        ];
    }

    /**
     * Persist an immutable message. A caller-provided UUID makes retries safe;
     * authorship is derived from the authenticated user and is never accepted
     * from request input.
     *
     * @return array{message: array<string, mixed>, deduplicated: bool}
     */
    public function send(
        Project $project,
        User $author,
        string $body,
        ?string $clientMessageId = null,
        ?string $origin = null,
    ): array {
        if ($author->isAgent()) {
            $origin ??= AgentConversationMessage::ORIGIN_EXTERNAL;

            if (! in_array($origin, [
                AgentConversationMessage::ORIGIN_AGENT_TURN,
                AgentConversationMessage::ORIGIN_EXTERNAL,
            ], true)) {
                throw new InvalidArgumentException('Unsupported agent conversation origin.');
            }
        } else {
            $origin = null;
        }

        $attributes = [
            'project_id' => $project->id,
            'user_id' => $author->id,
            'author_kind' => $author->isAgent()
                ? AgentConversationMessage::AUTHOR_AGENT
                : AgentConversationMessage::AUTHOR_HUMAN,
            'body' => $body,
            'client_message_id' => $clientMessageId,
            'origin' => $origin,
        ];

        $deduplicated = false;

        if ($clientMessageId !== null) {
            $existing = $project->agentConversationMessages()
                ->where('user_id', $author->id)
                ->where('client_message_id', $clientMessageId)
                ->first();

            if ($existing !== null) {
                $deduplicated = true;
                $message = $existing;
            }
        }

        if (! isset($message)) {
            try {
                $message = $project->agentConversationMessages()->create($attributes);
            } catch (QueryException $exception) {
                if ($clientMessageId === null) {
                    throw $exception;
                }

                $message = $project->agentConversationMessages()
                    ->where('user_id', $author->id)
                    ->where('client_message_id', $clientMessageId)
                    ->firstOrFail();
                $deduplicated = true;
            }
        }

        $message->loadMissing('author:id,name');

        return [
            'message' => $this->payloadFor($message),
            'deduplicated' => $deduplicated,
        ];
    }

    /** @return array<string, mixed> */
    public function payloadFor(AgentConversationMessage $message): array
    {
        $message->loadMissing('author:id,name');

        return [
            'id' => $message->id,
            'body' => $message->body,
            'client_message_id' => $message->client_message_id,
            'origin' => $message->origin,
            'author' => [
                'id' => $message->author?->id,
                'name' => $message->author?->name ?? 'Unknown member',
                'kind' => $message->author_kind,
            ],
            'created_at' => $message->created_at?->toISOString(),
        ];
    }
}
