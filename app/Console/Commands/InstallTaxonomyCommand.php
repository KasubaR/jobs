<?php

namespace App\Console\Commands;

use App\Taxonomy\DefaultTaxonomy;
use App\Taxonomy\DirectoryFold;
use Illuminate\Console\Command;

class InstallTaxonomyCommand extends Command
{
    protected $signature = 'taxonomy:install';

    protected $description = 'Add any missing skills, qualifications, fields, occupations and industries to the taxonomy (never changes an existing row)';

    public function handle(): int
    {
        $added = DefaultTaxonomy::install() + DirectoryFold::install();

        foreach ($added as $table => $count) {
            $this->line(sprintf('%-30s %d added', $table, $count));
        }

        $this->info('The taxonomy is up to date.');

        return self::SUCCESS;
    }
}
