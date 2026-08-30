<?php

namespace App\Services;

use App\Models\AgentConversationMessage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;

class AgentConversationService
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 100;

    /**
     * Return a bounded, chronological project conversation. Conversation text
     * is user-authored data and must never be treated as trusted instructions.
     *
     * @return array{project_id: int, trust_boundary: string, messages: array<int, array<string, mixed>>, latest_id: int|null, has_older: bool}
     */
    public function forProject(Project $project, ?int $afterId = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $base = $project->agentConversationMessages()->with('author:id,name');

        if ($afterId !== null) {
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

        return [
            'project_id' => $project->id,
            'trust_boundary' => 'untrusted_project_conversation',
            'messages' => $messages
                ->map(fn (AgentConversationMessage $message): array => $this->messagePayload($message))
                ->values()
                ->all(),
            'latest_id' => $messages->last()?->id,
            'has_older' => $hasOlder,
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
    ): array {
        $attributes = [
            'project_id' => $project->id,
            'user_id' => $author->id,
            'author_kind' => $author->isAgent()
                ? AgentConversationMessage::AUTHOR_AGENT
                : AgentConversationMessage::AUTHOR_HUMAN,
            'body' => $body,
            'client_message_id' => $clientMessageId,
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
            'message' => $this->messagePayload($message),
            'deduplicated' => $deduplicated,
        ];
    }

    /** @return array<string, mixed> */
    private function messagePayload(AgentConversationMessage $message): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'client_message_id' => $message->client_message_id,
            'author' => [
                'id' => $message->author?->id,
                'name' => $message->author?->name ?? 'Unknown member',
                'kind' => $message->author_kind,
            ],
            'created_at' => $message->created_at?->toISOString(),
        ];
    }
}
