<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sources')
            ->where('website_url', 'https://www.tsc.gov.zm/')
            ->whereNull('collector')
            ->update(['collector' => 'manual_submission']);
    }

    public function down(): void
    {
        DB::table('sources')->where('collector', 'manual_submission')->update(['collector' => null]);
    }
};
