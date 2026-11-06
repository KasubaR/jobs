<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_job_posts', function (Blueprint $table) {
            // pending = waiting for cleaning and extraction; processed = done; failed = tried and could not.
            $table->string('processing_status', 12)->default('pending')->after('is_removed');
            $table->timestamp('processed_at')->nullable()->after('processing_status');
            $table->text('processing_error')->nullable()->after('processed_at');

            $table->index('processing_status');
        });
    }

    public function down(): void
    {
        Schema::table('raw_job_posts', function (Blueprint $table) {
            $table->dropIndex(['processing_status']);
            $table->dropColumn(['processing_status', 'processed_at', 'processing_error']);
        });
    }
};
