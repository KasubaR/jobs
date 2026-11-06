<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceRunError extends Model
{
    public const UPDATED_AT = null;

    /** Stages, in the order a collection goes through them. */
    public const STAGES = [
        'fetch' => 'Fetching',
        'listing' => 'Listing page',
        'ad_page' => 'Ad page',
        'store' => 'Storing',
        'other' => 'Other',
    ];

    protected $guarded = [];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SourceRun::class, 'source_run_id');
    }
}
