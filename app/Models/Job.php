<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** pending: not read yet. done: read. needs_review: read but unsure. failed: reading broke. no_text: nothing to read (text cleared or never kept). */
    public const ANALYSIS_STATUSES = ['pending', 'done', 'needs_review', 'failed', 'no_text'];

    /** rules, ai, mixed, or published_only (only the facts the ad published as data). */
    public const ANALYSIS_METHODS = ['rules', 'ai', 'mixed', 'published_only'];

    public const SALARY_PERIODS = ['hour', 'day', 'week', 'month', 'year'];

    /** Only the way to apply is kept, never the address. */
    public const APPLICATION_METHODS = ['online', 'email', 'post', 'in_person'];

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
            'employment_types' => 'array',
            'location_multiple' => 'boolean',
            'match_date' => 'date',
            'standardized_at' => 'datetime',
            'salary_min' => 'float',
            'salary_max' => 'float',
            'analysed_at' => 'datetime',
        ];
    }

    public function skills(): HasMany
    {
        return $this->hasMany(JobSkill::class);
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(JobQualification::class);
    }

    public function experiences(): HasMany
    {
        return $this->hasMany(JobExperience::class);
    }

    public function rawPost(): BelongsTo
    {
        return $this->belongsTo(RawJobPost::class, 'raw_job_post_id');
    }

    /** The source this job's ad came from. */
    public function sourceId(): ?int
    {
        return $this->rawPost?->source_id;
    }

    /** The job this one is a copy of, when it is marked duplicate. */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_job_id');
    }
}
