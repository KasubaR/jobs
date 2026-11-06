<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An ad was stored for the first time, or its text changed (a new version was stored).
 * This is the hand-off from collection to processing: the cleaning, extraction and
 * classification stage listens for it. Collection never needs to know what happens next.
 */
class RawJobPostStored
{
    use Dispatchable;

    /** @param 'new'|'updated' $reason */
    public function __construct(public int $rawJobPostId, public string $reason) {}
}
