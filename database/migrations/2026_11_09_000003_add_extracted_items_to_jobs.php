<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7, step 2: where the extracted items live.
 *
 * Skills, qualifications and experience each gain: how strongly the ad requires them (requirement_level),
 * an either-or group, where the item came from, a confidence, the words that justify it (evidence), whether
 * a person looked at it, and whether a person removed it (so a re-run never brings it back).
 * Qualifications point at the step 1 taxonomy. Jobs gain the classification and the analysis state.
 *
 * Existing rows keep working: `is_required` stays, and the new columns have defaults that match it.
 */
return new class extends Migration
{
    private const COMMON = ['requirement_level', 'alternative_group', 'source', 'confidence', 'evidence', 'reviewed', 'removed'];

    public function up(): void
    {
        foreach (['job_skills', 'job_experiences'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $this->commonColumns($t));
        }

        Schema::table('job_qualifications', function (Blueprint $t) {
            $t->foreignId('qualification_id')->nullable()->change();   // the old reference list is no longer the only target
            $t->string('education_level', 20)->nullable();             // school | certificate | diploma | bachelor | postgraduate | masters | phd
            $t->foreignId('academic_award_id')->nullable()->constrained('academic_awards')->nullOnDelete();
            $t->foreignId('professional_qualification_id')->nullable()->constrained('professional_qualifications')->nullOnDelete();
            $t->foreignId('professional_registration_id')->nullable()->constrained('professional_registrations')->nullOnDelete();
            $t->foreignId('field_id')->nullable()->constrained('fields_of_study')->nullOnDelete();
            $t->string('candidate_text', 255)->nullable();             // wording the taxonomy does not know yet; never forced into the nearest entry
            $this->commonColumns($t);
        });

        Schema::table('jobs', function (Blueprint $t) {
            $t->string('occupation_code', 4)->nullable()->index();      // ISCO-08, see the occupations table
            $t->string('seniority', 20)->nullable();                    // seniority_levels.key
            $t->string('department', 120)->nullable();
            $t->string('salary_period', 10)->nullable();                // hour | day | week | month | year
            $t->string('application_method', 12)->nullable();           // online | email | post | in_person (the address itself is never stored)
            $t->string('analysis_status', 15)->default('pending')->index(); // pending | done | needs_review | failed | no_text
            $t->string('analysis_method', 15)->nullable();              // rules | ai | mixed | published_only
            $t->unsignedSmallInteger('analysis_version')->default(0);
            $t->timestamp('analysed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $t) {
            $t->dropIndex(['occupation_code']);
            $t->dropIndex(['analysis_status']);
            $t->dropColumn(['occupation_code', 'seniority', 'department', 'salary_period', 'application_method', 'analysis_status', 'analysis_method', 'analysis_version', 'analysed_at']);
        });

        Schema::table('job_qualifications', function (Blueprint $t) {
            foreach (['academic_award_id', 'professional_qualification_id', 'professional_registration_id', 'field_id'] as $fk) {
                $t->dropConstrainedForeignId($fk);
            }
            $t->dropColumn(array_merge(['education_level', 'candidate_text'], self::COMMON));
        });

        foreach (['job_experiences', 'job_skills'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn(self::COMMON));
        }
    }

    private function commonColumns(Blueprint $t): void
    {
        $t->string('requirement_level', 10)->default('required'); // required | preferred | advantage | mentioned
        $t->unsignedSmallInteger('alternative_group')->nullable(); // items of a job sharing a number are either-or ("ACCA or CIMA")
        $t->string('source', 10)->default('rule');                // rule | ai | published | person
        $t->unsignedTinyInteger('confidence')->default(0);        // 0-100
        $t->string('evidence', 300)->nullable();                  // the words in the ad; cleared with the raw text
        $t->boolean('reviewed')->default(false);                  // a person looked at it
        $t->boolean('removed')->default(false);                   // a person removed it: a re-run must not bring it back
    }
};
