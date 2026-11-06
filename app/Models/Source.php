<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    public const SOURCE_TYPES = [
        'job_board' => 'Job board',
        'company' => 'Company',
        'government' => 'Government',
        'ngo' => 'NGO',
        'media' => 'Media',
        'education' => 'Education',
        'aggregator' => 'Aggregator',
    ];

    public const METHODS = [
        'rss' => 'RSS / feed',
        'api' => 'Public API',
        'html' => 'Public HTML pages',
        'manual' => 'Manual submission',
        'pending_review' => 'Not decided yet',
    ];

    public const FREQUENCIES = [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
    ];

    public const ACCESS_STATUSES = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'restricted' => 'Restricted',
    ];

    protected $guarded = [];

    /** Why access/active is being changed. Set before saving; recorded in source_access_logs. */
    public ?string $decisionNote = null;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'retain_raw' => 'boolean',
            'collector_config' => 'array',
            'last_collection_at' => 'datetime',
            'next_collection_at' => 'datetime',
            'access_decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A source may only be collected from once access has been approved.
        static::saving(function (Source $source) {
            if ($source->is_active && $source->access_status !== 'approved') {
                throw new \DomainException(
                    "Source '{$source->name}' cannot be activated while access_status is '{$source->access_status}'."
                );
            }

            // Turning a source back on starts it fresh: no failure streak, due immediately.
            if ($source->exists && $source->isDirty('is_active') && $source->is_active) {
                $source->consecutive_failures = 0;
                $source->next_collection_at = null;
            }
        });

        // Every change to access_status or is_active is logged: who, when, why.
        static::updated(function (Source $source) {
            foreach (['access_status', 'is_active'] as $field) {
                if (! $source->wasChanged($field)) {
                    continue;
                }

                $original = $source->getPrevious()[$field] ?? null;
                $format = fn ($v) => is_bool($v) ? ($v ? '1' : '0') : ($v === null ? null : (string) $v);

                SourceAccessLog::create([
                    'source_id' => $source->id,
                    'user_id' => auth()->id(),
                    'field' => $field,
                    'from_value' => $format($original),
                    'to_value' => $format($source->{$field}),
                    'note' => $source->decisionNote,
                ]);
            }
        });
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SourceRun::class);
    }

    public function rawPosts(): HasMany
    {
        return $this->hasMany(RawJobPost::class);
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(SourceAccessLog::class);
    }
}
