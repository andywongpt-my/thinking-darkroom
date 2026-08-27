<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit trail of every WebMCP agent tool call.
 */
class AgentToolCall extends Model
{
    use HasFactory;

    public const UPDATED_AT = null; // append-only audit log

    protected $fillable = [
        'project_id',
        'agent_id',
        'tool_name',
        'authority',
        'http_method',
        'path',
        'result_status',
        'input',
        'output_summary',
        'duration_ms',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'input' => 'array',
        'output_summary' => 'array',
        'duration_ms' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
