<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->json('collector_config')->nullable()->after('collector');
            $table->timestamp('next_collection_at')->nullable()->after('last_collection_at');
            // Minimum seconds between requests to this host. The robots.txt Crawl-delay can raise it.
            $table->unsignedSmallInteger('min_interval_seconds')->default(1)->after('collection_frequency');
        });

        Schema::table('source_runs', function (Blueprint $table) {
            $table->string('trigger', 20)->default('scheduled')->after('status'); // scheduled | manual
        });

        Schema::create('source_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('field', 30); // access_status | is_active
            $table->string('from_value')->nullable();
            $table->string('to_value')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['source_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_access_logs');

        Schema::table('source_runs', function (Blueprint $table) {
            $table->dropColumn('trigger');
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['collector_config', 'next_collection_at', 'min_interval_seconds']);
        });
    }
};
