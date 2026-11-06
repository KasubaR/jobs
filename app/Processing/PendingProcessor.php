<?php

namespace App\Processing;

use App\Models\RawJobPost;

/**
 * The placeholder used until the processing stage exists: it does nothing, so every
 * stored ad simply stays "pending" and nothing is lost.
 */
class PendingProcessor implements RawPostProcessor
{
    public function process(RawJobPost $post): bool
    {
        return false;
    }
}
