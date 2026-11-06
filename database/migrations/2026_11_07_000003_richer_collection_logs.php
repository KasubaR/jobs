<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_runs', function (Blueprint $table) {
            // items_found = ads discovered; new + updated + unchanged = ads collected; failed = ads that could not be read or stored.
            $table->unsignedInteger('items_unchanged')->default(0)->after('items_updated');
            $table->unsignedInteger('items_failed')->default(0)->after('items_unchanged');
        });

        Schema::create('source_run_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_run_id')->constrained()->cascadeOnDelete();
            $table->string('source_url', 500)->nullable();
            $table->string('stage', 20); // fetch | listing | ad_page | store | other
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();

            $table->index('source_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_run_errors');

        Schema::table('source_runs', function (Blueprint $table) {
            $table->dropColumn(['items_unchanged', 'items_failed']);
        });
    }
};
