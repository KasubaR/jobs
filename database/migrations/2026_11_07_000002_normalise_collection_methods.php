<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 1 used 'permitted_scraping'; the source screens know it as plain 'html'.
        DB::table('sources')->where('collection_method', 'permitted_scraping')->update(['collection_method' => 'html']);
    }

    public function down(): void
    {
        //
    }
};
