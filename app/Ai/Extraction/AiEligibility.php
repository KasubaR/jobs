<?php

namespace App\Ai\Extraction;

use App\Models\Job;
use App\Standardization\Text;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which adverts the AI may be asked about. It is the last resort, not the first reader:
 *
 *  - the source must allow it (off by default) and must keep its text
 *  - the vacancy must be a real one (not a copy, not rejected) that the rules have already read
 *  - the rules must have found no qualification asked for, and the text must show a sign that it names one
 *  - an advert is sent once; a second look needs --job (force)
 */
class AiEligibility
{
    /** Words that suggest an advert states an academic or professional requirement. */
    public const HINT = '/\b(degree|diploma|bachelor|b\.?sc|b\.?com|b\.?a\b|master|mba|phd|certificate|grade\s*12|o[- ]?level|a[- ]?level|qualification|acca|cima|zica|cpa|licen[cs]e|trade test|apprentice|graduate)\b/iu';

    /** Why this vacancy should not go to the AI, or null when it may. $force skips the "rules found nothing" tests. */
    public function reason(Job $job, bool $force = false): ?string
    {
        $post = $job->rawPost;
        $source = $post?->source;

        return match (true) {
            in_array($job->status, ['duplicate', 'rejected'], true) => 'Not a counted vacancy (copy or rejected).',
            ! in_array($job->analysis_status, ['done', 'needs_review'], true) => 'The rules have not read it yet.',
            ! $source?->ai_allowed => 'The source does not allow AI reading.',
            ! $source->retain_raw || $post->raw_content === null => 'The ad text is not kept.',
            $force => null,
            $this->alreadyRead($job) => 'Already read by the AI.',
            $this->rulesFound($job) => 'The rules already found a qualification.',
            preg_match(self::HINT, Text::plain($post->raw_content)) !== 1 => 'The text shows no sign of a qualification.',
            default => null,
        };
    }

    /** @return Collection<int, Job> up to $limit vacancies the AI may be asked about, oldest first */
    public function candidates(int $limit): Collection
    {
        $found = collect();

        Job::query()->with('rawPost.source')
            ->whereNotIn('status', ['duplicate', 'rejected'])->whereIn('analysis_status', ['done', 'needs_review'])
            ->whereHas('rawPost.source', fn ($q) => $q->where('ai_allowed', true)->where('retain_raw', true))
            ->orderBy('id')
            ->each(function (Job $job) use ($limit, &$found) {
                if ($this->reason($job) === null) {
                    $found->push($job);
                }

                return $found->count() < $limit;
            });

        return $found;
    }

    private function alreadyRead(Job $job): bool
    {
        return DB::table('ai_usage')->where('job_id', $job->id)->where('purpose', 'extract')->where('status', 'ok')->exists();
    }

    private function rulesFound(Job $job): bool
    {
        return DB::table('job_qualifications')->where('job_id', $job->id)->where('removed', false)
            ->whereIn('requirement_level', ['required', 'preferred'])->exists();
    }
}
