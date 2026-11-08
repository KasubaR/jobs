<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6, step 5. One row per pair of jobs that look like the same vacancy, with the score and the reason for it,
 * so a link is never a mystery. A pair a person rejected is kept, so it is never proposed again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_duplicate_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_a_id')->constrained('jobs')->cascadeOnDelete(); // always the lower id
            $table->foreignId('job_b_id')->constrained('jobs')->cascadeOnDelete();
            $table->unsignedTinyInteger('score');                 // 0 to 100
            $table->string('reason', 12);                         // exact_url | exact_text | score
            $table->json('breakdown')->nullable();                // points per part and any guard that applied
            $table->string('outcome', 10);                        // auto | possible | confirmed | rejected
            $table->string('decided_by', 10)->default('system');  // system | person
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['job_a_id', 'job_b_id']);
            $table->index('outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_duplicate_candidates');
    }
};
