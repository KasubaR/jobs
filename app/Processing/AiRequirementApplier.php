<?php

namespace App\Processing;

use App\Ai\Extraction\QualificationSchema;
use App\Ai\Extraction\ValidatedExtraction;
use App\Models\Job;
use App\Taxonomy\TaxonomyIndex;
use Illuminate\Support\Facades\DB;

/**
 * Stores what the AI found (after ExtractionValidator) as extra rows beside the rules' own, marked source = ai.
 *
 * The AI only adds. It never changes or removes a row the rules or a person made:
 *  - an item already stored (same qualification, skill or experience) is left as it is
 *  - if the stored one has a different strength, the job is sent to review rather than either being trusted
 *  - a row a person removed or reviewed keeps its place, so a removed item does not come back
 *  - wording the dictionaries do not know is kept as candidate text, never forced into the nearest entry
 *  - skills the dictionary does not know are counted and left out (the review of new skills is a later step)
 */
class AiRequirementApplier
{
    public function __construct(private TaxonomyIndex $taxonomy, private RequirementApplier $rules) {}

    /** @return array{added:int, existing:int, conflicts:int, unknown_skills:int, other:int, rejected:int, corrections:int, review:bool} */
    public function apply(Job $job, ValidatedExtraction $result): array
    {
        $summary = ['added' => 0, 'existing' => 0, 'conflicts' => 0, 'unknown_skills' => 0, 'other' => count($result->items('other_requirements')),
            'rejected' => count($result->rejected), 'corrections' => count($result->corrections), 'review' => $result->needsReview()];

        DB::transaction(function () use ($job, $result, &$summary) {
            $offset = $this->maxGroup($job);

            $stored = [
                'job_qualifications' => DB::table('job_qualifications')->where('job_id', $job->id)->get()->keyBy(fn ($r) => $this->rules->qualificationKey($r)),
                'job_skills' => DB::table('job_skills')->where('job_id', $job->id)->get()->keyBy(fn ($r) => (string) $r->skill_id),
                'job_experiences' => DB::table('job_experiences')->where('job_id', $job->id)->get()->keyBy(fn ($r) => $r->min_years.'|'.($r->max_years ?? '').'|'.mb_strtolower((string) $r->area)),
            ];

            foreach (['qualifications', 'professional_registrations'] as $section) {
                foreach ($result->items($section) as $item) {
                    $row = $section === 'qualifications' ? $this->qualification($item) : $this->professional($item);
                    $this->store('job_qualifications', $job, $row, $row['key'], $item, $stored, $offset, $summary);
                }
            }

            foreach ($result->items('skills') as $item) {
                $skill = $this->taxonomy->find('skill', $item['name']);
                if ($skill === null) {
                    $summary['unknown_skills']++;

                    continue;
                }
                $this->store('job_skills', $job, ['columns' => ['skill_id' => $skill->id]], (string) $skill->id, $item, $stored, $offset, $summary);
            }

            foreach ($result->items('experience_requirements') as $item) {
                $key = $item['years_min'].'|'.($item['years_max'] ?? '').'|'.mb_strtolower((string) $item['area']);
                $this->store('job_experiences', $job, ['columns' => ['min_years' => $item['years_min'], 'max_years' => $item['years_max'], 'area' => $item['area']]], $key, $item, $stored, $offset, $summary);
            }
        });

        $dirty = [];
        if ($summary['added'] > 0 && $job->analysis_method === 'rules') {
            $dirty['analysis_method'] = 'mixed';
        }
        if (($summary['review'] || $summary['conflicts'] > 0) && $job->analysis_status === 'done') {
            $dirty['analysis_status'] = 'needs_review';
        }
        $dirty && $job->forceFill($dirty)->save();

        $this->log($job, $summary);

        return $summary;
    }

    /** @param array<string, mixed> $item */
    private function qualification(array $item): array
    {
        $award = $this->taxonomy->find('award', $item['name']);
        $field = $item['field'] !== null ? $this->taxonomy->find('field', $item['field']) : null;
        $level = $item['level'];

        if ($award !== null) {
            if ($field === null && $award->field_key) {
                $field = collect($this->taxonomy->all('field'))->firstWhere('key', $award->field_key);
            }

            return $this->qualificationRow('award:'.$award->key, ['academic_award_id' => $award->id, 'named_key' => $award->key, 'education_level' => $award->level, 'field_id' => $field?->id]);
        }
        if ($level !== null && $field !== null) {
            return $this->qualificationRow('', ['education_level' => $level, 'field_id' => $field->id]);
        }

        // Not placeable: kept as the advert's own wording for a person, never forced onto the nearest entry.
        return $this->qualificationRow('', ['education_level' => $level, 'field_id' => $field?->id, 'candidate_text' => mb_substr($item['name'], 0, 255)]);
    }

    /** @param array<string, mixed> $item */
    private function professional(array $item): array
    {
        if ($p = $this->taxonomy->find('professional', $item['name'])) {
            return $this->qualificationRow('professional:'.$p->key, ['professional_qualification_id' => $p->id, 'named_key' => $p->key]);
        }
        if ($r = $this->taxonomy->find('registration', $item['name'])) {
            return $this->qualificationRow('registration:'.$r->key, ['professional_registration_id' => $r->id, 'named_key' => $r->key]);
        }

        return $this->qualificationRow('', ['candidate_text' => mb_substr($item['name'], 0, 255)]);
    }

    /** The columns of a job_qualifications row and its key, in the same shape the rules' own rows have. */
    private function qualificationRow(string $named, array $c): array
    {
        $key = implode('|', [$named, $c['education_level'] ?? '', $c['field_id'] ?? '', mb_strtolower((string) ($c['candidate_text'] ?? ''))]);
        $columns = [
            'education_level' => $c['education_level'] ?? null, 'academic_award_id' => $c['academic_award_id'] ?? null,
            'professional_qualification_id' => $c['professional_qualification_id'] ?? null,
            'professional_registration_id' => $c['professional_registration_id'] ?? null,
            'field_id' => $c['field_id'] ?? null, 'candidate_text' => $c['candidate_text'] ?? null,
        ];
        $columns['directory_qualification_id'] = $this->rules->directoryId($columns + ['named_key' => $c['named_key'] ?? null]);

        return ['key' => $key, 'columns' => $columns];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $item
     * @param array<string, \Illuminate\Support\Collection> $stored
     * @param array<string, int> $summary
     */
    private function store(string $table, Job $job, array $row, string $key, array $item, array &$stored, int $offset, array &$summary): void
    {
        $level = QualificationSchema::LEVEL_OF_TYPE[$item['requirement_type']];

        if ($existing = $stored[$table]->get($key)) {
            $existing->requirement_level !== $level && ! $existing->removed ? $summary['conflicts']++ : $summary['existing']++;

            return;
        }

        $columns = $row['columns'] + ($table === 'job_qualifications' ? ['original_text' => mb_substr($item['original_text'], 0, 120)] : []);

        DB::table($table)->insert($columns + ($table === 'job_experiences' ? [] : ['is_required' => $level === 'required']) + [
            'job_id' => $job->id, 'requirement_level' => $level,
            'alternative_group' => $item['alternative_group'] === null ? null : $item['alternative_group'] + $offset,
            'source' => 'ai', 'confidence' => (int) round($item['confidence'] * 100), 'evidence' => mb_substr($item['original_text'], 0, 300),
            'reviewed' => false, 'removed' => false,
        ]);
        $stored[$table]->put($key, (object) ['requirement_level' => $level, 'removed' => false]);
        $summary['added']++;
    }

    private function maxGroup(Job $job): int
    {
        $max = 0;
        foreach (['job_qualifications', 'job_skills', 'job_experiences'] as $table) {
            $max = max($max, (int) DB::table($table)->where('job_id', $job->id)->max('alternative_group'));
        }

        return $max;
    }

    private function log(Job $job, array $s): void
    {
        $note = "AI read: {$s['added']} added, {$s['existing']} already known, {$s['conflicts']} disagree with the rules, {$s['rejected']} rejected by the checks"
            .($s['unknown_skills'] ? ", {$s['unknown_skills']} skills not in the dictionary" : '').($s['other'] ? ", {$s['other']} other requirements not stored" : '').'.';

        DB::table('extraction_logs')->insert([
            'raw_job_post_id' => $job->raw_job_post_id, 'job_id' => $job->id, 'stage' => 'extract', 'extraction_version' => ExtractionProcessor::VERSION,
            'status' => $s['conflicts'] || $s['rejected'] ? 'warning' : 'ok', 'message' => $note, 'created_at' => now(),
        ]);
    }
}
