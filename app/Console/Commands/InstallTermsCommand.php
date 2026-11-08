<?php

namespace App\Console\Commands;

use App\Standardization\DefaultTerms;
use Illuminate\Console\Command;

class InstallTermsCommand extends Command
{
    protected $signature = 'terms:install';

    protected $description = 'Add any missing starting words and places to the dictionary (never overwrites a correction you made)';

    public function handle(): int
    {
        DefaultTerms::install();
        $this->info('The dictionary is up to date.');

        return self::SUCCESS;
    }
}
