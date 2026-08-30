<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'is_agent'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function ownedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'owner_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')
            ->withTimestamps()
            ->withPivot('role');
    }

    public function agentPresences(): HasMany
    {
        return $this->hasMany(AgentPresence::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(PhotographerDecision::class, 'photographer_id');
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(AgentToolCall::class, 'agent_id');
    }

    /**
     * Machine actor boundary: agent accounts can only ever PROPOSE, never
     * exercise photographer authority.
     */
    public function isAgent(): bool
    {
        return (bool) ($this->is_agent ?? false);
    }
}
