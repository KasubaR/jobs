<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * No users are seeded: create the single admin with `php artisan make:filament-user`.
     */
    public function run(): void
    {
        $this->call(SourceSeeder::class);
    }
}
