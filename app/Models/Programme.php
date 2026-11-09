<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A qualification offered by a provider. */
class Programme extends Model
{
    protected $guarded = [];

    protected $casts = ['verified_at' => 'datetime'];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'provider_id');
    }

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(DirectoryQualification::class, 'qualification_id');
    }

    public function evidence(): MorphMany
    {
        return $this->morphMany(Evidence::class, 'subject');
    }
}
