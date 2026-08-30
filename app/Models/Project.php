<?php

namespace App\Models;

use App\Domain\Domain;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'status', 'owner_id'];

    protected $casts = [
        'description' => 'string',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withTimestamps()
            ->withPivot('role');
    }

    public function agentPresences(): HasMany
    {
        return $this->hasMany(AgentPresence::class);
    }

    public function agentConversationMessages(): HasMany
    {
        return $this->hasMany(AgentConversationMessage::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    public function brief(): HasOne
    {
        return $this->hasOne(CreativeBrief::class)->latestOfMany();
    }

    public function creativeBriefs(): HasMany
    {
        return $this->hasMany(CreativeBrief::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(PhotographerDecision::class);
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(AgentToolCall::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(QaFinding::class);
    }

    /** Sprint 4 — non-destructive retouch derivatives. */
    public function derivatives(): HasMany
    {
        return $this->hasMany(PhotoDerivative::class);
    }

    /** Sprint 4 — LEARN: photographer-authored creative memory. */
    public function creativeMemories(): HasMany
    {
        return $this->hasMany(CreativeMemory::class);
    }

    public function brainstormSessions(): HasMany
    {
        return $this->hasMany(BrainstormSession::class);
    }

    public function creativeConcepts(): HasMany
    {
        return $this->hasMany(CreativeConcept::class);
    }

    public function currentCreativeDirection(): ?CreativeConcept
    {
        return $this->creativeConcepts()
            ->where('status', Domain::CONCEPT_STATUS_ADOPTED)
            ->latest('adopted_at')
            ->first();
    }

    /** Proposals currently eligible for execution by an authorized agent tool. */
    public function executableProposals(): HasMany
    {
        return $this->proposals()->where('status', Domain::STATE_APPROVED)
            ->whereNull('executed_at');
    }

    public function hasEligibleExecutableProposal(): bool
    {
        return $this->executableProposals()->exists();
    }
}
