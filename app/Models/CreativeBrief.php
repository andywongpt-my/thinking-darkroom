<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreativeBrief extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'client',
        'shoot_date',
        'location',
        'creative_direction',
        'tonality_notes',
        'deliverables',
        'status',
    ];

    protected $casts = [
        'shoot_date' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
