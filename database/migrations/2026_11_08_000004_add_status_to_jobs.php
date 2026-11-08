<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6, step 2. Every job gets a status that is never taken from "it is still on the website":
 * the dates and the source's own behaviour decide, and every change is written to a history with its reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->string('status', 12)->default('new')->after('review_status'); // new | processing | published | expired | closed | rejected | duplicate
            $table->string('status_reason', 255)->nullable()->after('status');
            $table->string('status_by', 10)->default('system')->after('status_reason'); // system | person
            $table->timestamp('status_changed_at')->nullable()->after('status_by');
            $table->date('ends_on')->nullable()->after('closing_date'); // closing date, or the assumed end when there is none
            $table->boolean('end_assumed')->default(false)->after('ends_on');

            $table->index('status');
            $table->index('ends_on');
        });

        Schema::create('job_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 12)->nullable();
            $table->string('to_status', 12);
            $table->string('reason', 255)->nullable();
            $table->string('changed_by', 10)->default('system'); // system | person
            $table->timestamp('created_at')->useCurrent();

            $table->index(['job_id', 'id']);
        });

        Schema::table('sources', function (Blueprint $table) {
            // Yes only when one run lists EVERY ad that is currently open. Only then can an ad's absence mean it closed.
            $table->boolean('lists_all_open_ads')->default(false)->after('retain_raw');
        });
    }

    public function down(): void
    {
        Schema::table('sources', fn (Blueprint $table) => $table->dropColumn('lists_all_open_ads'));
        Schema::dropIfExists('job_status_changes');
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['ends_on']);
            $table->dropColumn(['status', 'status_reason', 'status_by', 'status_changed_at', 'ends_on', 'end_assumed']);
        });
    }
};
