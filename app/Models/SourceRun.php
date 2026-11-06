<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function errors(): HasMany
    {
        return $this->hasMany(SourceRunError::class);
    }

    /** Ads collected = stored as new, stored as a changed version, or confirmed unchanged. */
    public function collectedCount(): int
    {
        return $this->items_new + $this->items_updated + $this->items_unchanged;
    }

    public function durationSeconds(): ?int
    {
        return $this->finished_at ? (int) $this->started_at->diffInSeconds($this->finished_at, true) : null;
    }
}
