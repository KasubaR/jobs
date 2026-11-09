<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7, step 1: the dictionaries and classifications the extraction engine maps wording onto.
 *
 * Qualification taxonomy, in five layers:
 *   1. education level            (already exists: the Phase 6 levels)
 *   2. academic qualification     academic_awards            BEng, Bachelor of Engineering, MBChB ...
 *   3. field of study             fields_of_study            Engineering > Mechanical Engineering ...
 *   4. professional qualification professional_qualifications ACCA, CIMA, PMP ...
 *   5. membership or registration professional_registrations  EIZ, HPCZ, LAZ practising certificate ...
 * plus skills, occupations (ISCO-08), industries (ISIC Rev. 4) and seniority levels.
 *
 * Every table has an `origin` (seed or person). The installer only adds what is missing, so a person's edit is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_awards', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name');
            $table->string('level', 20);               // school | certificate | diploma | bachelor | postgraduate | masters | phd
            $table->string('field_key', 80)->nullable(); // the field the name itself implies (BEng: engineering), when it does
            $table->json('aliases')->nullable();
            $table->string('origin', 10)->default('seed');
            $table->timestamps();
        });

        Schema::create('fields_of_study', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('fields_of_study')->nullOnDelete(); // null = a broad field
            $table->json('aliases')->nullable();
            $table->string('origin', 10)->default('seed');
            $table->timestamps();
        });

        Schema::create('professional_qualifications', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name');
            $table->string('body')->nullable();          // who awards it
            $table->string('field_key', 80)->nullable();
            $table->json('aliases')->nullable();
            $table->string('origin', 10)->default('seed');
            $table->timestamps();
        });

        Schema::create('professional_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name');
            $table->string('body')->nullable();
            $table->string('kind', 20);                  // membership | registration | licence
            $table->json('aliases')->nullable();
            $table->string('origin', 10)->default('seed');
            $table->timestamps();
        });

        Schema::create('occupations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 4)->unique();         // ISCO-08: 1 digit major, 2 sub-major, 4 unit group
            $table->string('title');
            $table->string('level', 10);                 // major | submajor | unit
            $table->string('parent_code', 4)->nullable();
            $table->json('aliases')->nullable();         // job titles that belong here
            $table->string('origin', 10)->default('seed');
            $table->timestamps();

            $table->index('level');
        });

        Schema::create('seniority_levels', function (Blueprint $table) {
            $table->id();
            $table->string('key', 20)->unique();
            $table->string('name');
            $table->unsignedTinyInteger('rank');
            $table->json('aliases')->nullable();         // words in a title that signal the level
            $table->string('origin', 10)->default('seed');
            $table->timestamps();
        });

        Schema::table('skills', function (Blueprint $table) {
            $table->string('category', 40)->nullable()->after('type');   // Finance, ICT, Engineering ...
            $table->string('origin', 10)->default('seed')->after('aliases');
        });

        Schema::table('industries', function (Blueprint $table) {
            $table->string('code', 2)->nullable()->unique()->after('slug'); // ISIC Rev. 4 section letter
            $table->json('aliases')->nullable()->after('code');
            $table->string('origin', 10)->default('seed')->after('aliases');
        });
    }

    public function down(): void
    {
        Schema::table('industries', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'aliases', 'origin']);
        });
        Schema::table('skills', fn (Blueprint $table) => $table->dropColumn(['category', 'origin']));

        foreach (['seniority_levels', 'occupations', 'professional_registrations', 'professional_qualifications', 'fields_of_study', 'academic_awards'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
