<?php

namespace App\Services;

use App\Collectors\AccessCheck;
use App\Collectors\CollectorRegistry;
use App\Collectors\ManualSubmissionCollector;
use App\Models\Source;
use App\Models\SourceRun;
use DomainException;

/**
 * The rules behind the admin screens, kept out of the UI so they can be tested
 * and reused: who may be switched on, how access decisions are recorded.
 */
class SourceManager
{
    public function __construct(private CollectorRegistry $registry) {}

    /** Record an access decision. Anything but "approved" also switches the source off. */
    public function decideAccess(Source $source, string $status, string $note): void
    {
        if (! array_key_exists($status, Source::ACCESS_STATUSES)) {
            throw new DomainException("Unknown access status '{$status}'.");
        }

        if (trim($note) === '') {
            throw new DomainException('A note is required: say what you checked or who gave permission.');
        }

        $source->decisionNote = $note;
        $source->update([
            'access_status' => $status,
            'access_decided_at' => now(),
            'access_decision_note' => $note,
            'is_active' => $status === 'approved' ? $source->is_active : false,
        ]);
    }

    /** @throws DomainException with the reason when the source cannot be switched on */
    public function activate(Source $source, ?string $note = null): void
    {
        foreach ($this->activationProblems($source) as $problem) {
            throw new DomainException($problem);
        }

        $source->decisionNote = $note;
        $source->update(['is_active' => true]);
    }

    public function deactivate(Source $source, ?string $note = null): void
    {
        $source->decisionNote = $note;
        $source->update(['is_active' => false]);
    }

    /** @return list<string> reasons this source cannot be switched on (empty = it can) */
    public function activationProblems(Source $source): array
    {
        $problems = [];

        if ($source->access_status !== 'approved') {
            $problems[] = "Access is '{$source->access_status}'. Record an approval first (Decide access).";
        }

        if (! $this->registry->has($source->collector)) {
            $problems[] = 'No collector is assigned to this source, so nothing can collect from it yet.';
        } elseif ($this->registry->for($source)->method() !== $source->collection_method) {
            $problems[] = "Its collector works by '{$this->registry->for($source)->method()}', but the source's collection method is '{$source->collection_method}'.";
        }

        // Manual sources have nothing to fetch, so they need no listing URL.
        if ($source->collection_method !== 'manual' && blank($source->job_listing_url)) {
            $problems[] = 'The job listing URL is empty.';
        }

        return $problems;
    }

    /** Safe access test. Makes at most a robots.txt and one page request; stores nothing. */
    public function testAccess(Source $source): AccessCheck
    {
        return $this->registry->for($source)->check($source);
    }

    /** One manual collection right now. */
    public function runNow(Source $source): SourceRun
    {
        return $this->registry->for($source)->collect($source, 'manual');
    }

    /** Store one pasted-in ad for a manual source. */
    public function submitAd(Source $source, array $ad): SourceRun
    {
        $collector = $this->registry->for($source);

        if (! $collector instanceof ManualSubmissionCollector) {
            throw new DomainException("'{$source->name}' is collected automatically; ads cannot be added by hand.");
        }

        return $collector->submit($source, $ad);
    }

    public function canBeDeleted(Source $source): bool
    {
        return ! $source->rawPosts()->exists();
    }
}
