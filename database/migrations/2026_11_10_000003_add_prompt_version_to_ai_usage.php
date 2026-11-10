<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which wording of the instructions a request used, so an answer can be tied to the prompt that produced it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage', fn (Blueprint $t) => $t->unsignedSmallInteger('prompt_version')->nullable()->after('purpose'));
    }

    public function down(): void
    {
        Schema::table('ai_usage', fn (Blueprint $t) => $t->dropColumn('prompt_version'));
    }
};
