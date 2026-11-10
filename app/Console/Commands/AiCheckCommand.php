<?php

namespace App\Console\Commands;

use App\Ai\AiClient;
use App\Ai\AiFailure;
use Illuminate\Console\Command;

class AiCheckCommand extends Command
{
    protected $signature = 'ai:check {--call : send one tiny test request to the AI service (a few tokens; no ad text)}';

    protected $description = 'Show the AI settings (never the key) and, with --call, test the connection';

    public function handle(AiClient $ai): int
    {
        $c = config('job_intel.ai');
        $this->line('Switched on (AI_EXTRACTION_ENABLED): '.($c['enabled'] ? 'yes' : 'no'));
        $this->line('Provider: '.$c['provider']);
        $this->line('Key set: '.(filled($c['gemini']['key']) ? 'yes' : 'NO'));
        $this->line('Model: '.($c['gemini']['model'] ?: 'NOT SET'));
        $this->line('Limits per day: '.$c['daily_call_limit'].' calls, $'.$c['daily_budget_usd']);
        $this->line('Prices set: '.($c['price_input_per_million'] > 0 || $c['price_output_per_million'] > 0 ? 'yes' : 'no (cost will not be recorded)'));

        $t = $ai->usageToday();
        $this->line("Today: {$t['calls']} calls ({$t['ok']} ok, {$t['failed']} failed, {$t['blocked']} blocked), cost \${$t['cost_usd']}".($t['cost_known'] ? '' : ' (some calls had no price)'));

        if (! $this->option('call')) {
            $this->comment('Run with --call to test the connection.');

            return self::SUCCESS;
        }

        try {
            $r = $ai->ask('Reply only with the JSON {"ok": true}.', 'Connection test.', 'check');
        } catch (AiFailure $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('The AI service answered. Tokens in/out: '.($r->inputTokens ?? '?').' / '.($r->outputTokens ?? '?'));

        return self::SUCCESS;
    }
}
