<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('website_url');
            $table->string('job_listing_url')->nullable();
            $table->string('source_type', 30);
            $table->string('collection_method', 30)->default('pending_review');
            $table->string('collection_frequency', 20)->default('daily');
            $table->string('access_status', 20)->default('pending'); // approved | restricted | pending
            $table->timestamp('access_decided_at')->nullable();
            $table->text('access_decision_note')->nullable();
            $table->timestamp('last_collection_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('retain_raw')->default(true); // false = never keep raw content (source refused)
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('source_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 20)->default('running'); // running | success | partial | failed
            $table->unsignedInteger('items_found')->default(0);
            $table->unsignedInteger('items_new')->default(0);
            $table->unsignedInteger('items_updated')->default(0);
            $table->unsignedInteger('http_errors')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('raw_job_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_url', 500);
            $table->string('external_id')->nullable();
            $table->string('raw_title', 500)->nullable();
            // Cleared after 90 days (or never stored when sources.retain_raw is false).
            $table->longText('raw_content')->nullable();
            $table->timestamp('raw_purged_at')->nullable();
            $table->char('content_hash', 64)->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->boolean('is_removed')->default(false);
            $table->timestamps();

            $table->unique(['source_id', 'source_url', 'content_hash'], 'raw_posts_version_unique');
            $table->index(['source_id', 'source_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_job_posts');
        Schema::dropIfExists('source_runs');
        Schema::dropIfExists('sources');
    }
};
