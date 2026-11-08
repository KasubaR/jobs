<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawJobPost extends Model
{
    public const PROCESSING_STATUSES = [
        'pending' => 'Waiting',
        'processed' => 'Processed',
        'failed' => 'Failed',
    ];

    protected $guarded = [];

    public function scopePending(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('processing_status', 'pending');
    }

    protected function casts(): array
    {
        return [
            'is_removed' => 'boolean',
            'fetched_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'raw_purged_at' => 'datetime',
            'processed_at' => 'datetime',
            'structured_data' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
