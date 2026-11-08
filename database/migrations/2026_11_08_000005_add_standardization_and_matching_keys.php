<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6, steps 3 and 4.
 *
 * Step 3 adds the standardized values NEXT TO the originals (employer_text, location_text, qualification_text,
 * experience_text, employment_type stay exactly as the ad wrote them). Step 4 adds the keys that duplicate
 * detection compares. Nothing here replaces an original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // standardized qualification
            $table->string('qualification_level', 20)->nullable()->after('qualification_text');
            $table->unsignedTinyInteger('qualification_rank')->nullable()->after('qualification_level');
            $table->string('qualification_label', 255)->nullable()->after('qualification_rank');
            $table->string('qualification_field', 120)->nullable()->after('qualification_label');
            // standardized experience (months; max is empty for "at least" or a single figure)
            $table->unsignedSmallInteger('experience_min_months')->nullable()->after('experience_text');
            $table->unsignedSmallInteger('experience_max_months')->nullable()->after('experience_min_months');
            $table->string('experience_label', 40)->nullable()->after('experience_max_months');
            // standardized employment types: a list of keys such as ["full_time","contract"]
            $table->json('employment_types')->nullable()->after('employment_type');
            // place: location_id (already there) is the most specific place found
            $table->boolean('location_multiple')->default(false)->after('location_id');

            // matching keys (step 4)
            $table->string('match_title', 300)->nullable();
            $table->string('match_employer', 200)->nullable();
            $table->string('match_location', 120)->nullable();
            $table->string('match_url', 500)->nullable();
            $table->date('match_date')->nullable();
            $table->char('content_fingerprint', 16)->nullable(); // 64-bit simhash of the cleaned ad text, hex
            $table->char('content_hash_norm', 64)->nullable();   // sha256 of the cleaned ad text, for exact copies

            $table->unsignedSmallInteger('standardization_version')->nullable();
            $table->timestamp('standardized_at')->nullable();

            $table->index(['match_employer', 'match_title'], 'jobs_match_employer_title');
            $table->index('content_hash_norm');
            $table->index('match_url');
        });

        // The dictionary: what a piece of text standardizes to. Seeded, and corrected by a person (origin = person).
        Schema::create('term_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 24);        // qualification_level | qualification | employment_type | location | experience | employer
            $table->string('normalized', 191); // the text, lower case, no punctuation
            $table->string('standard_value', 191)->nullable();
            $table->json('meta')->nullable();
            $table->string('origin', 10)->default('seed'); // seed | person
            $table->timestamps();

            $table->unique(['kind', 'normalized']);
        });

        // Values the rules could not standardize, per job. The screen groups them so one decision fixes them all.
        Schema::create('unrecognized_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);        // qualification | experience | employment_type | location
            $table->string('normalized', 191);
            $table->string('sample', 255);     // the text as the ad wrote it
            $table->string('reason', 12);      // unknown | ambiguous
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['job_id', 'kind', 'normalized']);
            $table->index(['kind', 'normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unrecognized_values');
        Schema::dropIfExists('term_mappings');
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropIndex('jobs_match_employer_title');
            $table->dropIndex(['content_hash_norm']);
            $table->dropIndex(['match_url']);
            $table->dropColumn([
                'qualification_level', 'qualification_rank', 'qualification_label', 'qualification_field',
                'experience_min_months', 'experience_max_months', 'experience_label', 'employment_types', 'location_multiple',
                'match_title', 'match_employer', 'match_location', 'match_url', 'match_date', 'content_fingerprint', 'content_hash_norm',
                'standardization_version', 'standardized_at',
            ]);
        });
    }
};
