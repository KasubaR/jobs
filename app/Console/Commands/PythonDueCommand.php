<?php

namespace App\Console\Commands;

use App\Collectors\UserAgent;
use App\Models\Source;
use Illuminate\Console\Command;

/**
 * What the Python worker asks: "which of my sources are due, and how should I behave?"
 * Only approved, active Python sources that are due appear. The worker takes the User-Agent
 * and the limits from here, so the app stays the one place those rules live.
 */
class PythonDueCommand extends Command
{
    protected $signature = 'python:due {--json : Print JSON, for the worker}';

    protected $description = 'List the approved, active Python-worker sources that are due for collection';

    public function handle(): int
    {
        $contact = config('job_intel.collector_contact');

        if (blank($contact)) {
            return $this->problem('COLLECTOR_CONTACT is not set in .env; the worker must identify itself with a contact address.');
        }

        $sources = Source::pythonWorker()->due()->orderBy('id')->get();

        if ($this->option('json')) {
            $this->line(json_encode([
                'user_agent' => UserAgent::make(),
                'max_response_bytes' => (int) config('job_intel.max_response_bytes'),
                'http_timeout' => (int) config('job_intel.http_timeout'),
                'sources' => $sources->map(fn (Source $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'website_url' => $s->website_url,
                    'listing_url' => $s->job_listing_url,
                    'min_interval_seconds' => (int) $s->min_interval_seconds,
                    'script' => $s->collector_config['python']['script'] ?? null,
                    'options' => ($s->collector_config['python']['options'] ?? []) ?: new \stdClass, // always a JSON object
                ])->all(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($sources->isEmpty()) {
            $this->info('No approved, active Python sources are due.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Source', 'Script', 'Listing URL'], $sources->map(fn (Source $s) => [
            $s->id, $s->name, $s->collector_config['python']['script'] ?? '(none)', $s->job_listing_url,
        ])->all());

        return self::SUCCESS;
    }

    private function problem(string $message): int
    {
        if ($this->option('json')) {
            $this->line(json_encode(['error' => $message]));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
