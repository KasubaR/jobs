<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A per-source switch: may an AI service read this source's ads? Off for every source until a person turns it on. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', fn (Blueprint $t) => $t->boolean('ai_allowed')->default(false)->after('retain_raw'));
    }

    public function down(): void
    {
        Schema::table('sources', fn (Blueprint $t) => $t->dropColumn('ai_allowed'));
    }
};
