<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_job_post_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('employer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_title_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('industry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('duplicate_of_job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->string('title', 500);
            $table->string('employment_type', 30)->nullable();
            $table->date('posted_date')->nullable();
            $table->date('closing_date')->nullable();
            $table->string('salary_text')->nullable();
            $table->decimal('salary_min', 12, 2)->nullable();
            $table->decimal('salary_max', 12, 2)->nullable();
            $table->string('source_url', 500);
            $table->unsignedTinyInteger('confidence')->default(0); // 0-100
            $table->string('review_status', 20)->default('auto'); // auto | needs_review | reviewed
            $table->unsignedSmallInteger('extraction_version')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->index('posted_date');
            $table->index('review_status');
        });

        Schema::create('job_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_required')->default(true);
            $table->unique(['job_id', 'skill_id']);
        });

        Schema::create('job_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('qualification_id')->constrained()->cascadeOnDelete();
            $table->string('field_of_study')->nullable();
            $table->boolean('is_required')->default(true);
        });

        Schema::create('job_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('min_years')->nullable();
            $table->unsignedTinyInteger('max_years')->nullable();
            $table->string('area')->nullable();
        });

        Schema::create('extraction_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_job_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage', 20); // clean | extract | classify
            $table->unsignedSmallInteger('extraction_version')->default(1);
            $table->string('status', 10); // ok | warning | error
            $table->text('message')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('analytics_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('metric');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('dimension')->nullable();
            $table->json('data');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['metric', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_snapshots');
        Schema::dropIfExists('extraction_logs');
        Schema::dropIfExists('job_experiences');
        Schema::dropIfExists('job_qualifications');
        Schema::dropIfExists('job_skills');
        Schema::dropIfExists('jobs');
    }
};
