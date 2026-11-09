<?php

use App\Taxonomy\DefaultTaxonomy;
use Illuminate\Database\Migrations\Migration;

/** Puts the starting taxonomy and dictionaries in place. Safe to repeat; never overwrites a person's edit. */
return new class extends Migration
{
    public function up(): void
    {
        DefaultTaxonomy::install();
    }

    public function down(): void
    {
        // The taxonomy is data a person may have corrected; it is dropped with its tables.
    }
};
