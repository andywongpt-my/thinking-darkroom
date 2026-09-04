<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    private const TURN_IDEMPOTENCY_NAMESPACE = '0f6e2a8e-9c3d-4f7a-9e1b-2c5d4e6f7a8b';

    public function up(): void
    {
        Schema::table('agent_conversation_messages', function (Blueprint $table) {
            $table->string('origin', 16)->nullable()->after('client_message_id');
        });

        /**
         * Agent-turn replies store a UUIDv5 derived from their project and
         * trigger id. Existing rows have no origin column, so compare their
         * stored UUID with the deterministic keys for human triggers before
         * treating the remaining agent messages as external replies.
         *
         * @var Collection<int|string, Collection<int, object>> $humanTriggersByProject
         */
        $humanTriggersByProject = DB::table('agent_conversation_messages')
            ->where('author_kind', 'human')
            ->orderBy('id')
            ->get(['id', 'project_id'])
            ->groupBy('project_id');

        DB::table('agent_conversation_messages')
            ->where('author_kind', 'agent')
            ->whereNotNull('client_message_id')
            ->orderBy('id')
            ->get(['id', 'project_id', 'client_message_id'])
            ->each(function (object $message) use ($humanTriggersByProject): void {
                /** @var Collection<int, object> $triggers */
                $triggers = $humanTriggersByProject->get($message->project_id, collect());

                foreach ($triggers as $trigger) {
                    $expected = Uuid::uuid5(
                        self::TURN_IDEMPOTENCY_NAMESPACE,
                        'agent-turn:'.$message->project_id.':'.$trigger->id,
                    )->toString();

                    if (strcasecmp((string) $message->client_message_id, $expected) !== 0) {
                        continue;
                    }

                    DB::table('agent_conversation_messages')
                        ->where('id', $message->id)
                        ->update(['origin' => 'agent_turn']);

                    return;
                }
            });

        DB::table('agent_conversation_messages')
            ->where('author_kind', 'agent')
            ->whereNull('origin')
            ->update(['origin' => 'external']);
    }

    public function down(): void
    {
        Schema::table('agent_conversation_messages', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
