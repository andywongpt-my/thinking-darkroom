<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentConversationMessage extends Model
{
    public const AUTHOR_AGENT = 'agent';

    public const AUTHOR_HUMAN = 'human';

    public const ORIGIN_AGENT_TURN = 'agent_turn';

    public const ORIGIN_EXTERNAL = 'external';

    protected $fillable = [
        'project_id',
        'user_id',
        'author_kind',
        'body',
        'client_message_id',
        'origin',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
