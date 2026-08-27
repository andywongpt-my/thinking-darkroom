<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OBSERVED/ANALYZED data about one photo — produced by a PhotoAnalysisProvider.
 *
 * Never a photographer decision; never changes selection_state. One current
 * row per photo (unique photo_id); re-analysis updates in place and records
 * the provider + provenance honestly.
 */
class PhotoObservationRecord extends Model
{
    use HasFactory;

    protected $table = 'photo_observations';

    protected $fillable = [
        'photo_id',
        'project_id',
        'payload',
        'provider',
        'provenance',
        'similarity_group',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
