<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per AI request: what was asked of which model, how it went and what it cost.
 * The text sent and the answer are NOT stored here, only sizes, so this table never holds an ad's words.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('model', 80)->nullable();
            $table->string('purpose', 40);                       // what the call was for, e.g. check, extract
            $table->string('status', 15);                        // ok | rate_limited | error | blocked
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('input_chars')->default(0);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();      // null = no price configured
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('error', 200)->nullable();            // a short reason, never the key or the ad
            $table->foreignId('job_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
