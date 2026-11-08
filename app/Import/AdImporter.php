<?php

namespace App\Import;

use App\Collectors\ManualSubmissionCollector;
use App\Collectors\PythonWorkerCollector;
use App\Models\Source;
use App\Models\SourceRun;

/** One way in for files of ads: the Import file button and the collect:import command both use this. */
class AdImporter
{
    public function __construct(private AdFileParser $parser) {}

    /**
     * Read and store a whole file as one run.
     *
     * @throws ImportFileException when the file as a whole cannot be used
     * @throws \RuntimeException when the source may not take imports (not approved, not active, not manual)
     */
    public function import(Source $source, string $content, string $format, string $trigger = 'import'): SourceRun
    {
        $parsed = $this->parser->parse($content, $format);

        // The Python worker's sources use their own collector, which also reschedules the source after each import.
        $collector = $source->collection_method === 'python'
            ? app(PythonWorkerCollector::class)
            : app(ManualSubmissionCollector::class);

        return $collector->submitMany($source, $parsed['rows'], $trigger);
    }

    /** The source named inside a file ("source": id or name), or null. */
    public function sourceNamedIn(string $content, string $format): Source|null
    {
        if ($format !== 'json') {
            return null;
        }

        try {
            $ref = $this->parser->parse($content, 'json')['source'];
        } catch (ImportFileException) {
            return null;
        }

        return $ref === null ? null : $this->find((string) $ref);
    }

    /** By id (digits) or exact name. */
    public function find(string $reference): ?Source
    {
        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        return ctype_digit($reference)
            ? Source::find((int) $reference)
            : Source::where('name', $reference)->first();
    }
}
