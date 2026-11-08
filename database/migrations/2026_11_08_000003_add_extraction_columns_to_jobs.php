<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6, step 1. The extraction stage keeps what it read as the ad wrote it. The employer, place,
 * qualification and experience stay as text here; standardizing them into reference records is a later
 * step and must never replace these originals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->string('employer_text', 200)->nullable()->after('title');
            $table->string('location_text', 255)->nullable()->after('employer_text');
            $table->string('qualification_text', 255)->nullable()->after('location_text');
            $table->string('experience_text', 255)->nullable()->after('qualification_text');
            $table->string('application_url', 500)->nullable()->after('source_url');
            $table->string('salary_currency', 10)->nullable()->after('salary_text');
            // Where each field came from: structured | rule | text | title
            $table->json('field_sources')->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn(['employer_text', 'location_text', 'qualification_text', 'experience_text', 'application_url', 'salary_currency', 'field_sources']);
        });
    }
};
