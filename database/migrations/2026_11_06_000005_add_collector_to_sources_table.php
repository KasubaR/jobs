<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->string('collector', 50)->nullable()->after('collection_method');
        });

        DB::table('sources')
            ->where('website_url', 'https://jobwebzambia.com/')
            ->update(['collector' => 'jobweb_feed']);
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('collector');
        });
    }
};
