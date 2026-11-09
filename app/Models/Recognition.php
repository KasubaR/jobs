<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** One authority's statement about a qualification in Zambia. Never set from a job ad. */
class Recognition extends Model
{
    protected $guarded = [];

    protected $casts = ['valid_from' => 'date', 'valid_to' => 'date', 'verified_at' => 'datetime'];

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(DirectoryQualification::class, 'qualification_id');
    }

    public function authority(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'authority_id');
    }

    public function evidence(): MorphMany
    {
        return $this->morphMany(Evidence::class, 'subject');
    }
}
