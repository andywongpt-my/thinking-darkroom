<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreativeConceptItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'creative_concept_id',
        'dimension',
        'label',
        'value',
        'source',
    ];

    public function concept(): BelongsTo
    {
        return $this->belongsTo(CreativeConcept::class, 'creative_concept_id');
    }
}
