<?php

namespace App\Console\Commands;

use App\Import\AdFileParser;
use App\Import\AdImporter;
use App\Import\ImportFileException;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The door for anything outside the app (a Python script, a spreadsheet export, a one-off file):
 * hand it a CSV or JSON file of ads and it stores them through the same rules as every other
 * collector. The source must already be approved, active and manual.
 */
class ImportAdsCommand extends Command
{
    protected $signature = 'collect:import
        {file : Path to a .csv or .json file of ads}
        {--source= : Source id or exact name (not needed when the JSON file names it in "source")}
        {--trigger=import : What to show in the run list: import or python (the Python worker uses python)}
        {--json : Print the result as JSON, for scripts}';

    protected $description = 'Import a CSV or JSON file of ads into an approved, active manual or Python-worker source';

    public function handle(AdImporter $importer): int
    {
        $path = (string) $this->argument('file');
        $format = AdFileParser::formatFor($path);

        if (! is_file($path) || ! is_readable($path)) {
            return $this->stop("File not found or not readable: {$path}");
        }

        if ($format === null) {
            return $this->stop('Only .csv and .json files can be imported.');
        }

        $max = (int) config('job_intel.import_max_bytes');
        if (filesize($path) > $max) {
            return $this->stop('The file is larger than '.round($max / 1_048_576, 1).' MB.');
        }

        $trigger = (string) $this->option('trigger');
        if (! in_array($trigger, ['import', 'python'], true)) {
            return $this->stop("--trigger must be 'import' or 'python'.");
        }

        $content = (string) file_get_contents($path);

        $source = $this->option('source')
            ? $importer->find((string) $this->option('source'))
            : $importer->sourceNamedIn($content, $format);

        if (! $source) {
            return $this->stop($this->option('source')
                ? "No source matches '{$this->option('source')}'."
                : 'Say which source: --source=ID or NAME, or put "source" in a JSON file.');
        }

        try {
            $run = $importer->import($source, $content, $format, $trigger);
        } catch (ImportFileException|RuntimeException $e) {
            return $this->stop($e->getMessage());
        }

        $errors = $run->errors()->orderBy('id')->get(['source_url', 'stage', 'message']);

        if ($this->option('json')) {
            $this->line(json_encode([
                'run_id' => $run->id,
                'source' => $source->name,
                'status' => $run->status,
                'discovered' => $run->items_found,
                'new' => $run->items_new,
                'changed' => $run->items_updated,
                'unchanged' => $run->items_unchanged,
                'failed' => $run->items_failed,
                'errors' => $errors->map(fn ($e) => ['stage' => $e->stage, 'url' => $e->source_url, 'message' => $e->message])->all(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line("{$source->name}: {$run->status}. Discovered {$run->items_found}, new {$run->items_new}, changed {$run->items_updated}, unchanged {$run->items_unchanged}, failed {$run->items_failed}.");
            foreach ($errors as $error) {
                $this->warn("  - {$error->message}");
            }
        }

        // Partial is a success (the rest was stored); only "nothing stored" is a failure for the caller.
        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function stop(string $message): int
    {
        if ($this->option('json')) {
            $this->line(json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
