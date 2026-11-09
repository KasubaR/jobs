<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What employers who ask for a qualification also ask for or are: occupation, skill, industry.
 *
 * These are OBSERVED links, counted from job ads and rebuilt from them (stats:rebuild). They say "vacancies that ask
 * for X are mostly for Y", never "X leads to Y". `qualification_jobs` is the number of vacancies asking for the
 * qualification, so a share can always be judged against its sample size.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qualification_link_stats', function (Blueprint $t) {
            $t->id();
            $t->foreignId('directory_qualification_id')->constrained('directory_qualifications')->cascadeOnDelete();
            $t->string('link_type', 12);                 // occupation | skill | industry
            $t->string('target', 40);                    // occupation code, skill id or industry id
            $t->unsignedInteger('jobs');                 // vacancies asking for the qualification that also have this target
            $t->unsignedInteger('required_jobs');        // of those, where the qualification is required (not just preferred)
            $t->unsignedInteger('qualification_jobs');   // all vacancies asking for the qualification
            $t->timestamp('computed_at');

            $t->unique(['directory_qualification_id', 'link_type', 'target'], 'qls_unique');
            $t->index(['link_type', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualification_link_stats');
    }
};
