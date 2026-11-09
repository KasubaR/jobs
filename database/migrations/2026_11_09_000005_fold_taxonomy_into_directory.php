<?php

use App\Taxonomy\DirectoryFold;
use Illuminate\Database\Migrations\Migration;

/** Copies the taxonomy qualification rows into the directory and links existing job rows. Safe to repeat. */
return new class extends Migration
{
    public function up(): void
    {
        DirectoryFold::install();
    }

    public function down(): void
    {
        // dropped with the directory tables
    }
};
