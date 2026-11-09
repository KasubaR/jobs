<?php

namespace App\Console\Commands;

use App\Import\DirectoryImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ImportDirectoryCommand extends Command
{
    protected $signature = 'directory:import
        {type : organizations, qualifications, recognitions or programmes}
        {file : Path to a .csv file}';

    protected $description = 'Load organizations, qualifications, recognitions or programmes into the qualification directory from a CSV file';

    public function handle(DirectoryImporter $importer): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 5 * 1024 * 1024) {
            $this->error('The file cannot be read, or is over 5 MB.');

            return self::FAILURE;
        }

        try {
            $result = $importer->import((string) $this->argument('type'), (string) file_get_contents($path));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Read {$result['read']} rows: {$result['created']} created, {$result['updated']} updated, ".count($result['errors']).' with problems.');
        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return $result['created'] + $result['updated'] > 0 || $result['read'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
