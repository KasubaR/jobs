<?php

namespace App\Console\Commands;

use App\Ai\AiClient;
use App\Ai\AiFailure;
use App\Ai\Evaluation\AdvertSet;
use App\Ai\Evaluation\Scorer;
use App\Ai\Extraction\QualificationExtractor;
use App\Ai\Extraction\QualificationSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Runs the test adverts through the real AI model and scores each answer against the written one.
 *
 * It sends advert text to the AI provider and costs money, so it shows how many adverts and a rough cost first and asks.
 * The hold-out third is for one final score per prompt version: it needs --final and will not run twice without --again.
 * Changes nothing in the job data; writes a report to storage/app/ai-evaluations.
 */
class AiEvaluateCommand extends Command
{
    protected $signature = 'ai:evaluate
        {--split=tune : tune (default), holdout or all}
        {--final : needed for the hold-out adverts, which are scored once per prompt version}
        {--again : run the hold-out again for a prompt version that was already scored (it then stops being an unseen test)}
        {--with-multi : also run adverts that name several positions (shown apart, not scored)}
        {--only= : one advert, by its number (for example 16)}
        {--dry-run : show what would be sent and the rough cost, send nothing}
        {--yes : do not ask first}';

    protected $description = 'Run the test adverts through the AI and score the answers against the written ones';

    public function handle(AiClient $ai, QualificationExtractor $extractor, AdvertSet $set, Scorer $scorer): int
    {
        $split = (string) $this->option('split');
        if (! in_array($split, ['tune', 'holdout', 'all'], true)) {
            $this->error('The split must be tune, holdout or all.');

            return self::FAILURE;
        }
        $version = QualificationSchema::PROMPT_VERSION;

        if (in_array($split, ['holdout', 'all'], true)) {
            if (! $this->option('final')) {
                $this->error('The hold-out adverts are for one final score. Add --final when the prompt is finished being tuned.');

                return self::FAILURE;
            }
            if ($this->holdoutReports($version) && ! $this->option('again')) {
                $this->error("The hold-out has already been scored for prompt version {$version}. Running it again makes it a tuning set; add --again if you mean that.");

                return self::FAILURE;
            }
        }

        $adverts = $set->split($split, (bool) $this->option('with-multi'));
        if ($only = $this->option('only')) {
            $adverts = array_values(array_filter($adverts, fn ($a) => str_starts_with($a['file'], sprintf('%02d-', (int) $only))));
        }
        if ($adverts === []) {
            $this->error('No adverts match.');

            return self::FAILURE;
        }

        $this->estimate($adverts);

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        if ($why = $ai->unavailable()) {
            $this->error($why);

            return self::FAILURE;
        }
        if (! $this->option('yes') && ! $this->confirm('Send these adverts to the AI provider now?', false)) {
            $this->line('Nothing sent.');

            return self::SUCCESS;
        }

        $startedAt = (int) DB::table('ai_usage')->max('id');
        $rows = [];

        foreach ($adverts as $ad) {
            $row = ['file' => $ad['file'], 'title' => $ad['title'], 'sector' => $ad['sector'], 'multi' => $ad['multi_position'], 'status' => 'ok'];

            try {
                $result = $extractor->extract($ad['text'], null, 'evaluate');
            } catch (AiFailure $e) {
                $row['status'] = $e->kind === 'blocked' ? 'blocked' : 'failed';
                $row['error'] = $e->getMessage();
                $rows[] = $row;
                $this->line(sprintf('  %-44s %s: %s', mb_strimwidth($ad['title'], 0, 44, '…'), $row['status'], $e->getMessage()));
                if ($e->kind === 'blocked' || $e->status === 403 || $e->status === 401) {
                    $this->warn('Stopping: further adverts would meet the same problem.');
                    break;
                }

                continue;
            }

            if (! $result->ok) {
                $row['status'] = 'refused';
                $row['error'] = $result->error;
                $rows[] = $row;
                $this->line(sprintf('  %-44s answer refused: %s', mb_strimwidth($ad['title'], 0, 44, '…'), $result->error));

                continue;
            }

            $score = $scorer->score($ad['expected'], $result->data);
            $row += ['score' => $score, 'answer' => $result->data, 'rejected' => $result->rejected, 'corrections' => $result->corrections, 'needs_review' => $result->needsReview()];
            $rows[] = $row;

            $m = $this->counts($score);
            $this->line(sprintf('  %-44s expected %2d  got %2d  matched %2d  strength %s%s', mb_strimwidth($ad['title'], 0, 44, '…'), $m['expected'], $m['got'], $m['matched'], $m['strength'], $ad['multi_position'] ? '  (several positions: not scored)' : ''));
        }

        $scored = array_values(array_filter($rows, fn ($r) => isset($r['score']) && ! $r['multi']));
        $summary = $scorer->summarise(array_map(fn ($r) => ['sector' => $r['sector'], 'score' => $r['score']], $scored));
        $usage = $this->usage($startedAt);

        $this->report($summary, $scored, $rows, $usage);

        $path = $this->save($version, $split, $rows, $summary, $usage);
        $this->newLine();
        $this->info("Report saved: {$path}");

        return self::SUCCESS;
    }

    private function estimate(array $adverts): void
    {
        $instructions = mb_strlen(QualificationSchema::instructions());
        $in = (int) round(array_sum(array_map(fn ($a) => $instructions + mb_strlen($a['text']), $adverts)) / 4);
        $out = count($adverts) * 600;
        $inPrice = (float) config('job_intel.ai.price_input_per_million');
        $outPrice = (float) config('job_intel.ai.price_output_per_million');
        $long = array_filter($adverts, fn ($a) => mb_strlen($a['text']) > (int) config('job_intel.ai.max_input_chars'));

        $this->info(count($adverts).' adverts ('.$this->option('split').'), prompt version '.QualificationSchema::PROMPT_VERSION.', model '.(config('job_intel.ai.gemini.model') ?: 'NOT SET'));
        $this->line("About {$in} tokens in and {$out} out (a rough guess from text length).");
        $this->line($inPrice > 0 || $outPrice > 0
            ? sprintf('Rough cost: $%.4f at your configured prices.', ($in * $inPrice + $out * $outPrice) / 1_000_000)
            : 'No prices are set in .env (AI_PRICE_INPUT_PER_MILLION / AI_PRICE_OUTPUT_PER_MILLION), so the cost cannot be worked out.');
        $long && $this->warn(count($long).' advert(s) are longer than AI_MAX_INPUT_CHARS and will be refused: '.implode(', ', array_map(fn ($a) => $a['file'], $long)));
        $this->line('Advert text goes to the AI provider. Daily limits still apply.');
    }

    /** @return array{expected:int, got:int, matched:int, strength:string} */
    private function counts(array $score): array
    {
        $e = $g = $m = $ok = 0;
        foreach ($score['sections'] as $s) {
            $e += $s['expected'];
            $g += $s['got'];
            $m += count($s['matched']);
            $ok += count(array_filter($s['matched'], fn ($x) => $x['result'] === 'correct'));
        }

        return ['expected' => $e, 'got' => $g, 'matched' => $m, 'strength' => $m ? "{$ok}/{$m} right" : '-'];
    }

    private function usage(int $afterId): array
    {
        $q = DB::table('ai_usage')->where('id', '>', $afterId)->where('purpose', 'evaluate');

        return [
            'calls' => (clone $q)->count(), 'ok' => (clone $q)->where('status', 'ok')->count(),
            'input_tokens' => (int) (clone $q)->sum('input_tokens'), 'output_tokens' => (int) (clone $q)->sum('output_tokens'),
            'cost_usd' => (clone $q)->whereNotNull('cost_usd')->exists() ? round((float) (clone $q)->sum('cost_usd'), 6) : null,
            'seconds' => round((int) (clone $q)->sum('duration_ms') / 1000, 1),
        ];
    }

    private function pct(?float $v): string
    {
        return $v === null ? '   -' : sprintf('%3d%%', round($v * 100));
    }

    private function report(array $summary, array $scored, array $rows, array $usage): void
    {
        $this->newLine();
        $this->info('SCORE (adverts that name one position; the draft answers are the standard)');
        $t = $summary['total'];
        $this->line(sprintf('  Items: %d expected, %d given, %d matched.   Precision %s   Recall %s   Right strength %s', $t['expected'], $t['got'], $t['matched'], $this->pct($t['precision']), $this->pct($t['recall']), $this->pct($t['strength'])));
        $this->line(sprintf('  Strength errors: %d overstated (given stronger than the draft), %d understated.', $t['overstated'], $t['understated']));
        $f = $summary['qualification_found'];
        $this->line(sprintf('  "Qualification found": right on %d of %d adverts (%d missed, %d invented).', $f['both_yes'] + $f['both_no'], array_sum($f), $f['missed'], $f['invented']));

        $this->newLine();
        $this->line('  By list:');
        foreach ($summary['by_section'] as $name => $s) {
            $this->line(sprintf('    %-28s precision %s  recall %s  strength %s   (%d expected)', $name, $this->pct($s['precision']), $this->pct($s['recall']), $this->pct($s['strength']), $s['expected']));
        }
        $this->line('  By sector:');
        foreach ($summary['by_sector'] as $name => $s) {
            $this->line(sprintf('    %-28s precision %s  recall %s  strength %s   (%d expected)', $name, $this->pct($s['precision']), $this->pct($s['recall']), $this->pct($s['strength']), $s['expected']));
        }
        $this->line('  By how strongly the draft says it is asked for (found = recall, right = given the same strength):');
        foreach ($summary['by_expected_type'] as $name => $s) {
            $this->line(sprintf('    %-12s found %s of %d   right strength %d', $name, $this->pct($s['recall']), $s['expected'], $s['strength_ok']));
        }

        $lists = ['OVERSTATED (the AI said it was asked for more strongly than the draft: the error that matters most)' => 'overstated', 'UNDERSTATED' => 'understated'];
        foreach ($lists as $title => $kind) {
            $lines = [];
            foreach ($scored as $r) {
                foreach ($r['score']['sections'] as $sec => $s) {
                    foreach ($s['matched'] as $m) {
                        $m['result'] === $kind && $lines[] = sprintf('    %s | %s: draft %s, AI %s', mb_strimwidth($r['title'], 0, 30, '…'), mb_strimwidth($m['item']['name'], 0, 48, '…'), $m['expected'], $m['got']);
                    }
                }
            }
            if ($lines) {
                $this->newLine();
                $this->line('  '.$title);
                array_map(fn ($l) => $this->line($l), $lines);
            }
        }

        foreach (['missed' => 'MISSED (in the draft, not found by the AI)', 'extra' => 'EXTRA (found by the AI, not in the draft: check whether the draft is the one that is wrong)'] as $key => $title) {
            $lines = [];
            foreach ($scored as $r) {
                foreach ($r['score']['sections'] as $sec => $s) {
                    foreach ($s[$key] as $x) {
                        $lines[] = sprintf('    %s | %s [%s]: %s', mb_strimwidth($r['title'], 0, 30, '…'), mb_strimwidth($x['name'], 0, 52, '…'), $sec === 'professional_registrations' ? 'registration' : ($sec === 'qualifications' ? 'qualification' : 'experience'), $x['type']);
                    }
                }
            }
            if ($lines) {
                $this->newLine();
                $this->line('  '.$title);
                array_map(fn ($l) => $this->line($l), $lines);
            }
        }

        $rejected = [];
        foreach ($rows as $r) {
            foreach ($r['rejected'] ?? [] as $x) {
                $rejected[] = sprintf('    %s | %s: %s', mb_strimwidth($r['title'], 0, 30, '…'), $x['section'], $x['reason']);
            }
        }
        if ($rejected) {
            $this->newLine();
            $this->line('  REJECTED BY THE CHECKER (invented, or breaking a rule): '.count($rejected));
            array_map(fn ($l) => $this->line($l), $rejected);
        }

        $failed = array_filter($rows, fn ($r) => $r['status'] !== 'ok');
        if ($failed) {
            $this->newLine();
            $this->warn(count($failed).' advert(s) got no usable answer: '.implode(', ', array_map(fn ($r) => $r['file'].' ('.$r['status'].')', $failed)));
        }

        $this->newLine();
        $this->line(sprintf('  Calls %d (%d ok), tokens in/out %d / %d, cost %s, %.1f s of waiting.', $usage['calls'], $usage['ok'], $usage['input_tokens'], $usage['output_tokens'], $usage['cost_usd'] === null ? 'not known (no prices set)' : '$'.$usage['cost_usd'], $usage['seconds']));
    }

    private function save(int $version, string $split, array $rows, array $summary, array $usage): string
    {
        $dir = storage_path('app/ai-evaluations');
        File::ensureDirectoryExists($dir);
        $path = sprintf('%s/%s-prompt%d-%s.json', $dir, now()->format('Ymd-His'), $version, $split);

        File::put($path, json_encode([
            'run_at' => now()->toDateTimeString(), 'prompt_version' => $version, 'schema_version' => QualificationSchema::VERSION,
            'split' => $split, 'model' => config('job_intel.ai.gemini.model'), 'summary' => $summary, 'usage' => $usage, 'adverts' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /** @return list<string> */
    private function holdoutReports(int $version): array
    {
        // a run that included the hold-out adverts (holdout, or all) used up the unseen test
        return array_merge(glob(storage_path("app/ai-evaluations/*-prompt{$version}-holdout.json")) ?: [], glob(storage_path("app/ai-evaluations/*-prompt{$version}-all.json")) ?: []);
    }
}
