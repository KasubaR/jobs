<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One clean job record, made from a stored ad by the processing stage. */
class Job extends Model
{
    protected $table = 'jobs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'posted_date' => 'date',
            'closing_date' => 'date',
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
