<?php

namespace App\Models\Concerns;

use App\Models\Job;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a skill, qualification and experience row of a job have in common. */
trait IsExtractedItem
{
    /** required: must, minimum. preferred: desirable. advantage: an added advantage. mentioned: in the ad, not asked for. */
    public const LEVELS = ['required', 'preferred', 'advantage', 'mentioned'];

    /** The levels that count as "what employers ask for" in statistics. Advantage is shown apart, mentioned is ignored. */
    public const COUNTED_LEVELS = ['required', 'preferred'];

    /** rule: our patterns. ai: a model. published: the ad gave it as data. person: a person set it (final). */
    public const SOURCES = ['rule', 'ai', 'published', 'person'];

    public function initializeIsExtractedItem(): void
    {
        $this->timestamps = false;
        $this->guarded = [];
        $this->mergeCasts(['reviewed' => 'boolean', 'removed' => 'boolean']);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** Items that were not removed by a person. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('removed', false);
    }

    /** Items that count towards "what employers ask for". */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('removed', false)->whereIn('requirement_level', self::COUNTED_LEVELS);
    }

    /** A person decided this one: a re-run of extraction must leave it alone. */
    public function isFinal(): bool
    {
        return $this->source === 'person' || $this->reviewed || $this->removed;
    }
}
