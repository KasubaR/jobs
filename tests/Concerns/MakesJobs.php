<?php

namespace Tests\Concerns;

use App\Jobs\ProcessRawJobPost;
use App\Models\Job;
use App\Models\RawJobPost;
use App\Models\Source;

/** Builds jobs through the real pipeline (stored ad, then extraction, standardizing, status and duplicate detection). */
trait MakesJobs
{
    /** @return array{company: Source, boardA: Source, boardB: Source, gov: Source} */
    protected function sourceSet(): array
    {
        $all = Source::orderBy('id')->take(4)->get();
        foreach (['company', 'job_board', 'job_board', 'government'] as $i => $type) {
            $all[$i]->update(['source_type' => $type]);
        }

        return ['company' => $all[0]->fresh(), 'boardA' => $all[1]->fresh(), 'boardB' => $all[2]->fresh(), 'gov' => $all[3]->fresh()];
    }

    /** About 60 meaningful words, so the ad has a text fingerprint. $swap changes one word. */
    protected static function mineBody(?string $swap = null): string
    {
        $text = 'The mining engineer will plan and supervise underground and open pit operations, manage blasting schedules, '
            .'monitor ventilation and ground conditions, and prepare production reports for the mine manager. '
            .'The successful candidate must hold a recognised engineering degree, understand safety regulations, lead shift teams '
            .'and work closely with geologists and surveyors. Experience with mine planning software and cost control is an advantage for this position at the Solwezi operation.';

        return $swap ? str_replace('ventilation', $swap, $text) : $text;
    }

    protected static function cleanerBody(): string
    {
        return 'The cleaner keeps the office, kitchen and toilets tidy every morning, restocks soap and paper, empties the bins, '
            .'reports broken fittings to the facilities officer and follows the cleaning checklist for each floor. '
            .'Applicants should be reliable, honest and able to start at six in the morning, and must provide two references from previous employers who can confirm good conduct.';
    }

    /**
     * One job, as a source's ad would give it. $facts are the published job data, $ad the stored ad's own columns.
     * Defaults describe the same Mining Engineer vacancy every time, so a test only names what differs.
     */
    protected function ad(Source $source, array $facts = [], array $ad = []): Job
    {
        static $n = 0;
        $n++;

        $facts = array_replace([
            'employer' => 'Sample Mining Ltd', 'locations' => [['locality' => 'Solwezi']],
            'valid_through' => '2026-12-15', 'date_posted' => '2026-11-01',
        ], $facts);
        $facts = array_filter($facts, fn ($v) => $v !== null);

        $post = RawJobPost::create($ad + [
            'source_id' => $source->id, 'source_url' => "https://{$source->id}.test/job-{$n}", 'raw_title' => 'Mining Engineer',
            'raw_content' => '<p>'.self::mineBody().'</p>', 'structured_data' => $facts, 'content_hash' => hash('sha256', "ad{$n}"),
            'fetched_at' => now(), 'first_seen_at' => now()->subDays(2), 'last_seen_at' => now(),
        ]);

        ProcessRawJobPost::dispatchSync($post->id);

        return Job::where('raw_job_post_id', $post->id)->firstOrFail();
    }
}
