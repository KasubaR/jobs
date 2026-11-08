<?php

namespace App\Standardization;

use App\Models\Job;
use App\Models\RawJobPost;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6, steps 3 and 4 applied to one job: standardized values next to the originals, then the matching keys.
 *
 * It only ever writes the standardized and key columns. employer_text, location_text, qualification_text,
 * experience_text and employment_type (what the ad wrote) are read, never changed. A value the rules cannot
 * standardize is left empty and listed in unrecognized_values for a person.
 */
class JobStandardizer
{
    /** Raised when the rules improve, so older jobs can be found and redone (jobs:standardize). */
    public const VERSION = 1;

    public function __construct(
        private TermDictionary $terms,
        private QualificationParser $qualifications,
        private ExperienceParser $experience,
        private EmploymentTypeParser $employmentTypes,
        private LocationResolver $locations,
        private EmployerResolver $employers,
        private MatchingKeys $keys,
    ) {}

    public function standardize(Job $job, ?RawJobPost $post = null): void
    {
        $post ??= $job->rawPost;
        $unrecognized = [];
        $note = function (string $kind, string $key, string $sample, string $reason) use (&$unrecognized) {
            $unrecognized["{$kind}|{$key}"] = ['kind' => $kind, 'normalized' => mb_substr($key, 0, 191), 'sample' => mb_substr($sample, 0, 255), 'reason' => $reason];
        };

        $attrs = [
            'qualification_level' => null, 'qualification_rank' => null, 'qualification_label' => null, 'qualification_field' => null,
            'experience_min_months' => null, 'experience_max_months' => null, 'experience_label' => null,
            'employment_types' => null, 'location_id' => null, 'location_multiple' => false, 'employer_id' => null,
        ];

        $q = $this->qualifications->parse($job->qualification_text);
        if ($q['status'] === 'ok') {
            $attrs['qualification_level'] = $q['level'];
            $attrs['qualification_rank'] = $q['rank'];
            $attrs['qualification_label'] = $q['label'];
            $attrs['qualification_field'] = $q['field'];
        } elseif (in_array($q['status'], ['unknown', 'ambiguous'], true)) {
            $note('qualification', $q['key'], (string) $job->qualification_text, $q['status']);
        }

        $e = $this->experience->parse($job->experience_text);
        if ($e['status'] === 'ok') {
            $attrs['experience_min_months'] = $e['min'];
            $attrs['experience_max_months'] = $e['max'];
            $attrs['experience_label'] = $e['label'];
        } elseif (in_array($e['status'], ['unknown', 'ambiguous'], true)) {
            $note('experience', $e['key'], (string) $job->experience_text, $e['status']);
        }

        $t = $this->employmentTypes->parse($job->employment_type);
        $attrs['employment_types'] = $t['types'] ?: null;
        foreach ($t['unknown'] as $u) {
            $note('employment_type', $u['key'], $u['sample'], 'unknown');
        }

        $l = $this->locations->resolve($job->location_text);
        $attrs['location_id'] = $l['location_id'];
        $attrs['location_multiple'] = $l['multiple'];
        foreach ($l['unknown'] as $u) {
            $note('location', $u['key'], $u['sample'], 'unknown');
        }

        $attrs['employer_id'] = $this->employers->resolve($job->employer_text);

        $job->forceFill($attrs + ['standardization_version' => self::VERSION, 'standardized_at' => now()]);
        $job->forceFill($this->keys->build($job, $post?->raw_content));
        $job->save();

        DB::table('unrecognized_values')->where('job_id', $job->id)->delete();
        foreach ($unrecognized as $row) {
            DB::table('unrecognized_values')->insert($row + ['job_id' => $job->id, 'created_at' => now()]);
        }
    }

    /** After a mapping is saved: redo the jobs that were waiting on exactly that value. Returns how many. */
    public function reapply(string $kind, string $normalized): int
    {
        $this->terms->flush();
        $count = 0;

        foreach (DB::table('unrecognized_values')->where('kind', $kind)->where('normalized', $normalized)->pluck('job_id') as $id) {
            if ($job = Job::find($id)) {
                $this->standardize($job);
                $count++;
            }
        }

        return $count;
    }
}
