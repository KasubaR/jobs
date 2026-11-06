<?php

namespace App\Processing;

use App\Models\RawJobPost;

/**
 * The seam between collection and the next stage (cleaning, extraction, classification).
 * The processing stage supplies a class implementing this and binds it in AppServiceProvider.
 */
interface RawPostProcessor
{
    /**
     * Process one stored ad.
     *
     * @return bool true when the ad is finished (it is then marked "processed");
     *              false to leave it pending. Throwing marks it "failed" with the message.
     */
    public function process(RawJobPost $post): bool;
}
