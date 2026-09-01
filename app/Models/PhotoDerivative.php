<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 4 — one non-destructive derivative of an original photo.
 *
 * Invariants:
 *  - storage_path NEVER equals the original photo's path.
 *  - adjustments is the exact validated, normalized set used to render.
 *  - provenance honestly names the renderer that produced the file.
 *  - one derivative per (photo, type) — repeated execution never duplicates.
 */
class PhotoDerivative extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'photo_id',
        'proposal_id',
        'type',
        'storage_path',
        'adjustments',
        'provenance',
        'created_by',
    ];

    protected $casts = [
        'adjustments' => 'array',
    ];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
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
