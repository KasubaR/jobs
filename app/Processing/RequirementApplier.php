<?php

namespace App\Processing;

use App\Extraction\RequirementReader;
use App\Models\Job;
use App\Models\RawJobPost;
use App\Standardization\Text;
use App\Taxonomy\TaxonomyIndex;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7, step 3: reads one job's ad with RequirementReader and stores what it asks for, plus the job's occupation,
 * seniority and analysis state.
 *
 * A person's decision is never overwritten. A row a person added, reviewed or removed is kept exactly as it is, and
 * reading again neither duplicates it nor brings a removed item back. Everything else (what our rules read earlier)
 * is replaced, so a re-run after the rules improve gives the new reading and no stale leftovers.
 */
class RequirementApplier
{
    public function __construct(private RequirementReader $reader, private TaxonomyIndex $taxonomy) {}

    public function apply(Job $job, RawJobPost $post): void
    {
        $sources = $job->field_sources ?? [];
        $body = $post->raw_content === null ? null : Text::plain($post->raw_content);
        $published = fn (string $field) => ($sources[$field] ?? null) === 'structured' ? $job->{$field} : null;

        $read = $this->reader->read($body, $published('qualification_text'), $published('experience_text'));
        $found = $read['qualifications'] || $read['skills'] || $read['experiences'];

        DB::transaction(function () use ($job, $read) {
            $offset = $this->maxKeptGroup($job);

            $this->replace('job_qualifications', $job, $read['qualifications'], $this->qualificationKey(...), fn ($r) => [
                'education_level' => $r['education_level'] ?? null,
                'academic_award_id' => $r['academic_award_id'] ?? null,
                'professional_qualification_id' => $r['professional_qualification_id'] ?? null,
                'professional_registration_id' => $r['professional_registration_id'] ?? null,
                'directory_qualification_id' => $this->directoryId($r),
                'field_id' => $r['field_id'] ?? null,
                'candidate_text' => $r['candidate_text'] ?? null,
            ], $offset);
            $this->replace('job_skills', $job, $read['skills'], fn ($r) => (string) $r->skill_id, fn ($r) => ['skill_id' => $r['skill_id']], $offset);
            $this->replace('job_experiences', $job, $read['experiences'], fn ($r) => $r->min_years.'|'.($r->max_years ?? '').'|'.mb_strtolower((string) $r->area),
                fn ($r) => ['min_years' => $r['min_years'], 'max_years' => $r['max_years'], 'area' => $r['area']], $offset);

            $title = (string) $job->title;
            $job->forceFill([
                'occupation_code' => $this->occupationFor($title),
                'seniority' => $this->taxonomy->seniority($title)->key,
            ]);
        });

        $job->forceFill([
            'analysis_status' => ! $read['read_text'] && ! $found ? 'no_text' : ($read['review'] ? 'needs_review' : 'done'),
            'analysis_method' => $read['read_text'] ? 'rules' : 'published_only',
            'analysis_version' => RequirementReader::VERSION,
            'analysed_at' => now(),
        ])->save();
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @param  callable(object): string  $rowKey  the key of a stored row (matches the reader's key for the same item)
     * @param  callable(array): array  $columns  the table's own columns for an item
     */
    private function replace(string $table, Job $job, array $items, callable $rowKey, callable $columns, int $groupOffset): void
    {
        $kept = DB::table($table)->where('job_id', $job->id)->where(fn ($q) => $q->where('removed', true)->orWhere('reviewed', true)->orWhere('source', 'person'))->get();
        DB::table($table)->where('job_id', $job->id)->where('removed', false)->where('reviewed', false)->where('source', '!=', 'person')->delete();

        $taken = $kept->mapWithKeys(fn ($r) => [$rowKey($r) => true]);

        foreach ($items as $item) {
            if ($taken->has($item['key'])) {
                continue;
            }

            $level = $item['requirement_level'];
            DB::table($table)->insert($columns($item) + ($table === 'job_experiences' ? [] : ['is_required' => $level === 'required']) + [
                'job_id' => $job->id,
                'requirement_level' => $level,
                'alternative_group' => ! isset($item['alternative_group']) ? null : $item['alternative_group'] + $groupOffset,
                'source' => $item['source'],
                'confidence' => $item['confidence'],
                'evidence' => $item['evidence'],
                'reviewed' => false,
                'removed' => false,
            ]);
        }
    }

    /** The key a stored qualification row has, in the same shape as the reader's key for the same item. */
    private function qualificationKey(object $r): string
    {
        $named = match (true) {
            $r->academic_award_id !== null => 'award:'.DB::table('academic_awards')->where('id', $r->academic_award_id)->value('key'),
            $r->professional_qualification_id !== null => 'professional:'.DB::table('professional_qualifications')->where('id', $r->professional_qualification_id)->value('key'),
            $r->professional_registration_id !== null => 'registration:'.DB::table('professional_registrations')->where('id', $r->professional_registration_id)->value('key'),
            default => '',
        };

        return implode('|', [$named, $r->education_level ?? '', $r->field_id ?? '', mb_strtolower((string) $r->candidate_text)]);
    }

    private function directoryId(array $r): ?int
    {
        [$table, $key] = match (true) {
            isset($r['academic_award_id']) => ['academic_awards', $r['named_key']],
            isset($r['professional_qualification_id']) => ['professional_qualifications', $r['named_key']],
            isset($r['professional_registration_id']) => ['professional_registrations', $r['named_key']],
            default => [null, null],
        };

        if ($table === null) {
            return ! empty($r['education_level']) && ! empty($r['field_id']) ? $this->levelFieldEntry($r['education_level'], (int) $r['field_id']) : null;
        }

        return DB::table('directory_qualifications')->where('legacy_table', $table)->where('legacy_key', $key)->value('id');
    }

    /**
     * "Bachelor's in Accounting", "BSc in Accountancy" and "First Degree in Accounting" are one directory entry:
     * bachelor + accounting. Found or created here (unverified, origin 'extraction') so it has a profile, demand and links.
     */
    private function levelFieldEntry(string $level, int $fieldId): ?int
    {
        $levelRow = DB::table('qualification_levels')->where('key', $level)->first();
        $field = DB::table('fields_of_study')->where('id', $fieldId)->first();
        if (! $levelRow || ! $field) {
            return null;
        }

        $key = "academic.{$level}.{$field->key}";
        DB::table('directory_qualifications')->insertOrIgnore([
            'key' => $key, 'name' => "{$levelRow->name} in {$field->name}", 'kind' => 'academic', 'level_id' => $levelRow->id,
            'field_id' => $field->id, 'status' => 'unverified', 'origin' => 'extraction', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('directory_qualifications')->where('key', $key)->value('id');
    }

    /** Titles come as "Analyst – Budgeting at World Vision" or "M&E (Monitoring) Expert – Roads": try the whole title, then the role part. */
    private function occupationFor(string $title): ?string
    {
        $noEmployer = preg_replace('/\s+at\s+[^–—-]*$/iu', '', $title);
        $noBrackets = trim(preg_replace('/\s*[(\[].*?[)\]]/u', '', $noEmployer));

        foreach ([$title, $noEmployer, $noBrackets, preg_split('/\s+[–—-]\s+/u', $noBrackets)[0]] as $candidate) {
            if ($code = $this->taxonomy->occupation(trim($candidate))?->code) {
                return $code;
            }
        }

        return null;
    }

    /** New alternative groups are numbered after the ones kept rows already use, so they never merge. */
    private function maxKeptGroup(Job $job): int
    {
        $max = 0;
        foreach (['job_qualifications', 'job_skills', 'job_experiences'] as $table) {
            $max = max($max, (int) DB::table($table)->where('job_id', $job->id)
                ->where(fn ($q) => $q->where('removed', true)->orWhere('reviewed', true)->orWhere('source', 'person'))->max('alternative_group'));
        }

        return $max;
    }
}
