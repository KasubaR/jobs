<?php

namespace App\Processing;

use App\Models\Job;
use App\Models\RawJobPost;
use App\Models\SourceRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Decides a job's status. An ad being on a website is never the reason to call it open.
 *
 * Order of the rules (first that applies wins):
 *   1. duplicate                   left alone (duplicate detection owns it)
 *   2. rejected by a person        left alone
 *   3. rejected (automatic)        the title says it is a tender or notice, not a vacancy
 *   4. expired                     the closing date is past; with no closing date, the assumed end is past
 *   5. closed                      the source lists every open ad and this one was missing from enough successful runs
 *   6. new                         too little found in the ad; waits for a person
 *   7. published                   everything else
 * A person's own decision (published, or new) overrides rules 3, 6 and 7, but never the dates (4) or the source (5).
 * Every change is written to job_status_changes with its reason.
 */
class JobStatusService
{
    /** Titles that are notices, not vacancies. Anchored at the start so "Tender Clerk" is still a job. */
    private const NOT_A_VACANCY = '~^\s*(?:tenders?\s*(?:for\b|no\b|number\b|notice\b|ref\b|[:\-#\d])|invitation (?:to|for) (?:bid|tender)s?\b|request for (?:quotations?|proposals?|expressions? of interest)\b|expressions? of interest\b|public notice\b|notice of\b|supply and delivery of\b)~i';

    public function evaluate(Job $job, ?CarbonInterface $today = null): ?string
    {
        $today = ($today ?? now())->copy()->startOfDay();

        if ($job->status === 'duplicate') {
            return null;
        }

        $byPerson = $job->status_by === 'person';

        if ($job->status === 'rejected' && $byPerson) {
            return null;
        }

        [$endsOn, $assumed] = $this->end($job);
        $job->forceFill(['ends_on' => $endsOn, 'end_assumed' => $assumed])->save();

        if (! $byPerson && preg_match(self::NOT_A_VACANCY, (string) $job->title)) {
            return $this->set($job, 'rejected', 'The title reads like a tender or notice, not a vacancy.');
        }

        if ($endsOn->lt($today)) {
            return $this->set($job, 'expired', $assumed
                ? "No closing date was given; the assumed end ({$endsOn->toDateString()}, ".config('job_intel.assumed_ad_age_days').' days after it appeared) has passed.'
                : "The closing date ({$endsOn->toDateString()}) has passed.");
        }

        if ($reason = $this->closedBySource($job)) {
            return $this->set($job, 'closed', $reason);
        }

        if ($byPerson && in_array($job->status, ['published', 'new'], true)) {
            return null; // the person's choice stands while no date or source says otherwise
        }

        if ($job->review_status === 'needs_review') {
            return $this->set($job, 'new', 'Waiting for review: too little could be read from the ad.');
        }

        return $this->set($job, 'published', 'Passed the checks and no end date has passed.');
    }

    /** A person's decision (a later screen calls this). Dates and the source can still expire or close it. */
    public function decide(Job $job, string $status, ?string $reason = null): void
    {
        $this->set($job, $status, $reason ?: 'Decided by a person.', 'person');
        $this->evaluate($job->refresh());
    }

    /** Re-checks every job; run daily so dates and sources move jobs on without a new ad arriving. */
    public function sweep(?CarbonInterface $today = null): array
    {
        $changes = [];

        Job::query()->whereNotIn('status', ['duplicate'])->with('rawPost.source')->chunkById(200, function ($jobs) use (&$changes, $today) {
            foreach ($jobs as $job) {
                $before = $job->status;
                $after = $this->evaluate($job, $today);

                if ($after !== null && $after !== $before) {
                    $key = "{$before} to {$after}";
                    $changes[$key] = ($changes[$key] ?? 0) + 1;
                }
            }
        });

        ksort($changes);

        return $changes;
    }

    /** The first history row for a new job. */
    public function started(Job $job): void
    {
        $this->history($job, null, $job->status, 'Read from the ad.', 'system');
    }

    /** @return array{0: \Carbon\Carbon, 1: bool} the end date and whether it is only assumed */
    private function end(Job $job): array
    {
        if ($job->closing_date !== null) {
            return [$job->closing_date->copy()->startOfDay(), false];
        }

        $from = $job->posted_date ?? $job->first_seen_at ?? now();

        return [$from->copy()->startOfDay()->addDays(max(1, (int) config('job_intel.assumed_ad_age_days'))), true];
    }

    /**
     * Only a source that says it lists every open ad can close a job, and only when the ad was missing from
     * enough successful runs in a row. A failed or partial run, or one that found nothing at all, proves nothing.
     */
    private function closedBySource(Job $job): ?string
    {
        $raw = $job->rawPost;
        $source = $raw?->source;

        if (! $source || ! $source->lists_all_open_ads) {
            return null;
        }

        $lastSeen = RawJobPost::where('source_id', $source->id)->where('source_url', $raw->source_url)->max('last_seen_at');
        if ($lastSeen === null) {
            return null;
        }

        $needed = max(1, (int) config('job_intel.closed_after_missing_runs'));
        $missed = SourceRun::where('source_id', $source->id)
            ->where('status', 'success')
            ->where('items_found', '>', 0)
            ->where('started_at', '>', $lastSeen)
            ->count();

        return $missed >= $needed
            ? "The source lists every open ad and this one was missing from the last {$missed} successful runs."
            : null;
    }

    private function set(Job $job, string $status, ?string $reason, string $by = 'system'): ?string
    {
        if (! in_array($status, Job::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown job status '{$status}'.");
        }

        $from = $job->status;

        if ($from === $status) {
            if ($job->status_reason !== $reason && $by === 'system') {
                $job->forceFill(['status_reason' => $reason])->save(); // the reason can sharpen without a change of status
            }

            return $status;
        }

        $job->forceFill(['status' => $status, 'status_reason' => $reason, 'status_by' => $by, 'status_changed_at' => now()])->save();
        $this->history($job, $from, $status, $reason, $by);

        return $status;
    }

    private function history(Job $job, ?string $from, string $to, ?string $reason, string $by): void
    {
        DB::table('job_status_changes')->insert([
            'job_id' => $job->id, 'from_status' => $from, 'to_status' => $to,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 255), 'changed_by' => $by, 'created_at' => now(),
        ]);
    }
}
