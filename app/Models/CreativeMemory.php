<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 4 — LEARN: one durable, photographer-authored creative memory.
 * Future agent proposals MUST consume these; the agent may never write them.
 */
class CreativeMemory extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'photographer_id',
        'proposal_id',
        'kind',
        'lesson',
        'created_by',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function photographer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }
}
