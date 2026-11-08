<?php

use App\Standardization\DefaultTerms;
use Illuminate\Database\Migrations\Migration;

/** Puts the starting dictionary and the Zambian places in place. Safe to repeat; never overwrites a person's correction. */
return new class extends Migration
{
    public function up(): void
    {
        DefaultTerms::install();
    }

    public function down(): void
    {
        // The dictionary is data a person may have corrected; it is dropped with its table.
    }
};
