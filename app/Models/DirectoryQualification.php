<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** What a qualification is. Recognition, availability and demand are separate (Recognition, Programme, JobQualification). */
class DirectoryQualification extends Model
{
    protected $guarded = [];

    protected $casts = ['aliases' => 'array'];

    public function level(): BelongsTo
    {
        return $this->belongsTo(QualificationLevel::class, 'level_id');
    }

    public function awardingOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'awarding_organization_id');
    }

    public function recognitions(): HasMany
    {
        return $this->hasMany(Recognition::class, 'qualification_id');
    }

    public function programmes(): HasMany
    {
        return $this->hasMany(Programme::class, 'qualification_id');
    }

    public function evidence(): MorphMany
    {
        return $this->morphMany(Evidence::class, 'subject');
    }
}
