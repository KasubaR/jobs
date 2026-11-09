<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qualification Directory (Domain A). Four facts, four places, each with its own evidence:
 *   existence     directory_qualifications (+ organizations, qualification_levels)
 *   recognition   recognitions
 *   availability  programmes
 *   demand        job_qualifications (already there; gains directory_qualification_id)
 * `evidence` is the one claim table: any row above can have sources and a verification date.
 *
 * The legacy flat `qualifications` table is left untouched; job_qualifications.qualification_id still points at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->string('key', 80)->unique();
            $t->string('name');
            $t->string('type', 20);                     // awarding_body | professional_body | university | college | regulator | exam_board
            $t->string('country', 2)->default('ZM');
            $t->string('website', 255)->nullable();
            $t->json('aliases')->nullable();
            $t->string('origin', 10)->default('seed');  // seed | person | extraction
            $t->timestamps();

            $t->index('type');
        });

        Schema::create('qualification_levels', function (Blueprint $t) {
            $t->id();
            $t->string('key', 20)->unique();            // the same words as job_qualifications.education_level
            $t->string('name');
            $t->unsignedTinyInteger('rank');
            $t->unsignedTinyInteger('zqf_level')->nullable(); // Zambia Qualifications Framework level, set only when verified
            $t->string('origin', 10)->default('seed');
            $t->timestamps();
        });

        Schema::create('directory_qualifications', function (Blueprint $t) {
            $t->id();
            $t->string('key', 90)->unique();
            $t->string('name');
            $t->string('kind', 15);                     // academic | professional | registration | vocational | short_course
            $t->string('registration_kind', 20)->nullable(); // membership | registration | licence (kind = registration only)
            $t->foreignId('level_id')->nullable()->constrained('qualification_levels')->nullOnDelete();
            $t->foreignId('field_id')->nullable()->constrained('fields_of_study')->nullOnDelete();
            $t->foreignId('awarding_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $t->unsignedSmallInteger('typical_duration_months')->nullable(); // only when verified
            $t->text('entry_requirements')->nullable();                     // only when verified
            $t->string('status', 12)->default('unverified');                // unverified | verified | retired
            $t->json('aliases')->nullable();
            $t->string('origin', 10)->default('seed');
            $t->string('legacy_table', 40)->nullable();  // where a folded-in row came from
            $t->string('legacy_key', 80)->nullable();
            $t->timestamps();

            $t->unique(['legacy_table', 'legacy_key']);
            $t->index(['kind', 'status']);
        });

        Schema::create('qualification_relationships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('from_id')->constrained('directory_qualifications')->cascadeOnDelete();
            $t->foreignId('to_id')->constrained('directory_qualifications')->cascadeOnDelete();
            $t->string('type', 12);                     // progression | equivalent | prerequisite | exemption
            $t->string('note', 255)->nullable();
            $t->timestamps();

            $t->unique(['from_id', 'to_id', 'type']);
        });

        Schema::create('recognitions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('qualification_id')->constrained('directory_qualifications')->cascadeOnDelete();
            $t->foreignId('authority_id')->constrained('organizations')->cascadeOnDelete(); // who says so
            $t->string('status', 15)->default('unknown'); // accredited | registered | recognised | not_recognised | unknown
            $t->string('scope', 255)->nullable();         // e.g. "for practice as an accountant"
            $t->string('reference', 120)->nullable();
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();

            $t->unique(['qualification_id', 'authority_id']);
        });

        Schema::create('programmes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('provider_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignId('qualification_id')->constrained('directory_qualifications')->cascadeOnDelete();
            $t->string('name')->nullable();               // the provider's own title for it
            $t->string('mode', 12)->nullable();           // full_time | part_time | distance | online | blended
            $t->unsignedSmallInteger('duration_months')->nullable();
            $t->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $t->string('accreditation_status', 15)->nullable(); // of this programme at this provider
            $t->string('url', 500)->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
        });

        Schema::create('evidence', function (Blueprint $t) {
            $t->id();
            $t->morphs('subject');                        // the row the claim is about
            $t->string('claim', 40);                      // the field or fact: existence, recognition, duration, offered ...
            $t->foreignId('source_id')->nullable()->constrained('sources')->nullOnDelete();
            $t->string('url', 500)->nullable();
            $t->string('document', 255)->nullable();      // a gazette, letter or prospectus, when there is no page
            $t->string('note', 500)->nullable();
            $t->date('retrieved_on')->nullable();
            $t->string('verified_by', 80)->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
        });

        Schema::table('job_qualifications', function (Blueprint $t) {
            $t->foreignId('directory_qualification_id')->nullable()->constrained('directory_qualifications')->nullOnDelete();
        });

        Schema::table('sources', function (Blueprint $t) {
            $t->string('purpose', 12)->default('jobs');   // jobs | directory | recognition
        });
    }

    public function down(): void
    {
        Schema::table('sources', fn (Blueprint $t) => $t->dropColumn('purpose'));
        Schema::table('job_qualifications', fn (Blueprint $t) => $t->dropConstrainedForeignId('directory_qualification_id'));

        foreach (['evidence', 'programmes', 'recognitions', 'qualification_relationships', 'directory_qualifications', 'qualification_levels', 'organizations'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
