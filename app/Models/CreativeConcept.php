<?php

namespace App\Models;

use App\Domain\Domain;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreativeConcept extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'brainstorm_session_id',
        'parent_concept_id',
        'title',
        'summary',
        'content',
        'status',
        'created_by',
        'lineage_basis',
        'adopted_at',
    ];

    protected $casts = [
        'content' => 'array',
        'lineage_basis' => 'array',
        'adopted_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function brainstormSession(): BelongsTo
    {
        return $this->belongsTo(BrainstormSession::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_concept_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_concept_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CreativeConceptItem::class);
    }

    public function isAdopted(): bool
    {
        return $this->status === Domain::CONCEPT_STATUS_ADOPTED;
    }
}
