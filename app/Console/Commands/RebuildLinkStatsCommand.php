<?php

namespace App\Console\Commands;

use App\Taxonomy\LinkStatsBuilder;
use Illuminate\Console\Command;

class RebuildLinkStatsCommand extends Command
{
    protected $signature = 'stats:rebuild';

    protected $description = 'Recount which occupations, skills and industries go with each qualification, from the current vacancies';

    public function handle(LinkStatsBuilder $builder): int
    {
        foreach ($builder->rebuild() as $type => $count) {
            $this->line(sprintf('%-12s %d links', $type, $count));
        }

        $this->info('Link statistics rebuilt.');

        return self::SUCCESS;
    }
}
