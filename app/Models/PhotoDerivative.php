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
 *  - reverted_at stamps a photographer revert (B3); the bytes stay, but the
 *    row no longer feeds executed-value surfaces. prior_photo_state archives
 *    the photo's retouch_state at execution time so revert restores it.
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
        'reverted_at',
        'prior_photo_state',
    ];

    protected $casts = [
        'adjustments' => 'array',
        'reverted_at' => 'datetime',
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
