<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A link between two qualifications. `basis` says whether it is an official determination or only an unverified comparison. */
class QualificationRelationship extends Model
{
    public const BASES = ['official', 'comparison'];

    protected $guarded = [];

    protected $casts = ['verified_at' => 'datetime'];

    public function evidence(): MorphMany
    {
        return $this->morphMany(Evidence::class, 'subject');
    }
}
