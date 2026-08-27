<?php

namespace App\Models;

use App\Domain\Domain;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Photo extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'filename',
        'original_name',
        'path',
        'mime',
        'size_bytes',
        'width',
        'height',
        'selection_state',
        'retouch_state',
        'camera_make',
        'camera_model',
        'lens',
        'iso',
        'aperture',
        'shutter_speed',
        'focal_length',
        'captured_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'iso' => 'decimal:1',
        'aperture' => 'decimal:2',
        'shutter_speed' => 'decimal:4',
        'focal_length' => 'decimal:2',
        'captured_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeUnreviewed($query)
    {
        return $query->where('selection_state', Domain::SELECTION_UNREVIEWED);
    }
}
