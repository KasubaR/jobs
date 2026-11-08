<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One clean job record, made from a stored ad by the processing stage. */
class Job extends Model
{
    /**
     * new        extracted, not yet passed the checks (for example waiting for a person to review a thin ad)
     * processing reserved for the standardizing and comparing stages
     * published  passed the checks and not known to be over
     * expired    its closing date (or assumed end) has passed
     * closed     the source stopped listing it (only for sources that list every open ad)
     * rejected   not a usable vacancy
     * duplicate  the same vacancy as another job (set by duplicate detection)
     */
    public const STATUSES = ['new', 'processing', 'published', 'expired', 'closed', 'rejected', 'duplicate'];

    protected $table = 'jobs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'posted_date' => 'date',
            'closing_date' => 'date',
            'ends_on' => 'date',
            'end_assumed' => 'boolean',
            'status_changed_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'field_sources' => 'array',
            'salary_min' => 'float',
            'salary_max' => 'float',
        ];
    }

    public function rawPost(): BelongsTo
    {
        return $this->belongsTo(RawJobPost::class, 'raw_job_post_id');
    }
}
