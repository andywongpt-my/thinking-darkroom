<?php

namespace App\Models;

use App\Domain\Domain;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proposal extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'created_by',
        'type',
        'status',
        'summary',
        'payload',
        'supersedes_id',
        'reviewed_at',
        'executed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'reviewed_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProposalItem::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(Proposal::class, 'supersedes_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(PhotographerDecision::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(QaFinding::class);
    }

    public function isEligibleForExecution(): bool
    {
        return $this->status === Domain::STATE_APPROVED && $this->executed_at === null;
    }
}
