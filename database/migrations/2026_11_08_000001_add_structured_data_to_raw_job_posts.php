<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_job_posts', function (Blueprint $table) {
            // Facts a page publishes about itself (schema.org JobPosting): title, employer, place, dates, pay...
            // Never the ad text, so it is kept when the raw text is cleared after 90 days.
            $table->json('structured_data')->nullable()->after('raw_content');
        });
    }

    public function down(): void
    {
        Schema::table('raw_job_posts', function (Blueprint $table) {
            $table->dropColumn('structured_data');
        });
    }
};
