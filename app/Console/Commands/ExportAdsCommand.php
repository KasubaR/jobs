<?php

namespace App\Console\Commands;

use App\Standardization\Text;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Writes the stored advert text of vacancies to plain text files, so real adverts can be picked for the extraction test set.
 * Read-only: nothing in the database changes. Files go to storage/app/<folder>, never to a public folder.
 */
class ExportAdsCommand extends Command
{
    protected $signature = 'ads:export {--folder=ad-export : folder name inside storage/app} {--limit=60 : most adverts to write} {--sector= : healthcare, engineering, finance, education, administration or other} {--all : include copies and rejected ads}';

    protected $description = 'Write stored advert text to files (one per vacancy, plus one combined file) for choosing test adverts';

    /** ISCO-08 sub-major group (first two digits of the occupation code) to a broad sector. A guess from the job title only. */
    private const SECTORS = [
        'healthcare' => ['22', '32', '53'],
        'engineering' => ['21', '31', '71', '72', '74'],
        'finance' => ['24', '33'],
        'education' => ['23', '34'],
        'administration' => ['41', '42', '43', '12', '11'],
    ];

    public function handle(): int
    {
        $folder = (string) $this->option('folder');
        if (! preg_match('/^[a-z0-9_-]{1,40}$/i', $folder)) {
            $this->error('The folder name may only use letters, numbers, - and _.');

            return self::FAILURE;
        }
        $sector = strtolower((string) $this->option('sector'));
        if ($sector !== '' && ! in_array($sector, [...array_keys(self::SECTORS), 'other'], true)) {
            $this->error('Sector must be one of: '.implode(', ', [...array_keys(self::SECTORS), 'other']).'.');

            return self::FAILURE;
        }

        $dir = storage_path('app/'.$folder);
        File::ensureDirectoryExists($dir);

        $jobs = DB::table('jobs')->join('raw_job_posts as r', 'r.id', '=', 'jobs.raw_job_post_id')->leftJoin('sources as s', 's.id', '=', 'r.source_id')
            ->whereNotNull('r.raw_content')
            ->when(! $this->option('all'), fn ($q) => $q->whereNotIn('jobs.status', ['duplicate', 'rejected']))
            ->orderBy('jobs.id')
            ->get(['jobs.id', 'jobs.title', 'jobs.employer_text', 'jobs.status', 'jobs.occupation_code', 'jobs.posted_date', 'jobs.source_url', 's.name as source', 'r.raw_content']);

        $written = [];
        $combined = '';

        foreach ($jobs as $job) {
            $guess = $this->sector($job->occupation_code);
            if ($sector !== '' && $guess !== $sector) {
                continue;
            }
            if (count($written) >= max(1, (int) $this->option('limit'))) {
                break;
            }

            $read = $this->whatWasRead($job->id);
            $header = implode("\n", [
                "ID: {$job->id}", "TITLE: {$job->title}", 'EMPLOYER: '.($job->employer_text ?: 'not read'), "SOURCE: {$job->source}",
                "SECTOR (guess from the title): {$guess}", "STATUS: {$job->status}", 'LINK: '.$job->source_url, 'WHAT THE SYSTEM READ: '.$read,
            ]);
            $body = Text::plain($job->raw_content);
            $text = $header."\n\n----- ADVERT TEXT -----\n".$body."\n";

            $file = sprintf('%s-%04d-%s.txt', $guess, $job->id, Str::slug(Str::limit((string) $job->title, 40, '')) ?: 'advert');
            File::put("{$dir}/{$file}", $text);
            $combined .= "\n\n=================================================================\n".$text;
            $written[] = [$job->id, $guess, mb_strlen($body), $job->title];
        }

        File::put("{$dir}/ALL-IN-ONE.txt", ltrim($combined));
        File::put("{$dir}/INDEX.txt", collect($written)->map(fn ($w) => sprintf('%-5d %-15s %6d chars  %s', ...$w))->implode("\n")."\n");

        $this->info(count($written)." adverts written to {$dir}");
        $this->line('  ALL-IN-ONE.txt has every advert in one file; INDEX.txt lists them. The files hold other people\'s advert text: do not publish them.');
        collect($written)->groupBy(1)->each(fn ($g, $k) => $this->line(sprintf('  %-15s %d', $k, $g->count())));

        return self::SUCCESS;
    }

    private function sector(?string $code): string
    {
        $group = $code === null ? '' : substr($code, 0, 2);
        foreach (self::SECTORS as $name => $groups) {
            if (in_array($group, $groups, true)) {
                return $name;
            }
        }

        return 'other';
    }

    /** A one-line summary of the qualifications the system found, so the right and wrong ones can be seen beside the advert. */
    private function whatWasRead(int $jobId): string
    {
        $rows = DB::table('job_qualifications as q')->leftJoin('directory_qualifications as d', 'd.id', '=', 'q.directory_qualification_id')
            ->where('q.job_id', $jobId)->where('q.removed', false)
            ->get(['d.name', 'q.education_level', 'q.candidate_text', 'q.requirement_level', 'q.source']);

        if ($rows->isEmpty()) {
            return 'no qualification found';
        }

        return $rows->map(fn ($r) => ($r->name ?: $r->candidate_text ?: ucfirst((string) $r->education_level)).' ['.$r->requirement_level.', '.$r->source.']')->implode('; ');
    }
}
