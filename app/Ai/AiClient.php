<?php

namespace App\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * The one door to an AI model. Everything that wants a model's help goes through here, so the safety rules live in one place:
 *
 *  - nothing is sent unless AI_EXTRACTION_ENABLED is true and a key and model are set
 *  - an over-long text is refused, not cut, so a caller never gets an answer about half an ad
 *  - a daily call limit and a daily money limit stop further calls (the attempt is logged as blocked)
 *  - a request that fails for a passing reason (timeout, rate limit, server error) is tried again, waiting longer each time
 *    and honouring the service's own "retry after"; a refused key or a bad request is not retried
 *  - every request is recorded in ai_usage: size, tokens, cost and outcome, never the text or the key
 */
class AiClient
{
    public function __construct(private AiProvider $provider) {}

    public function enabled(): bool
    {
        return (bool) config('job_intel.ai.enabled');
    }

    /** Why a request cannot be made right now, or null when one can. */
    public function unavailable(): ?string
    {
        return match (true) {
            ! $this->enabled() => 'AI is switched off (AI_EXTRACTION_ENABLED).',
            ! $this->provider->configured() => 'AI is not set up: the key or the model is missing in .env.',
            default => null,
        };
    }

    /** @throws AiFailure */
    public function ask(string $system, string $prompt, string $purpose, ?int $jobId = null, bool $json = true): AiResponse
    {
        if ($reason = $this->unavailable()) {
            throw new AiFailure($reason, kind: 'off');
        }

        $size = mb_strlen($system) + mb_strlen($prompt);
        if ($size > (int) config('job_intel.ai.max_input_chars')) {
            $this->refuse($purpose, $size, $jobId, 'Text longer than AI_MAX_INPUT_CHARS.');
        }
        if ($reason = $this->limitReached()) {
            $this->refuse($purpose, $size, $jobId, $reason);
        }

        $started = microtime(true);
        $max = max(1, (int) config('job_intel.ai.max_attempts'));
        $failure = null;
        $attempt = 0;

        while ($attempt < $max) {
            $attempt++;
            try {
                $response = $this->provider->generate($system, $prompt, $json);
                $this->record($purpose, 'ok', $attempt, null, $size, $response->inputTokens, $response->outputTokens, $started, null, $jobId);

                return $response;
            } catch (AiFailure $e) {
                $failure = $e;
                if (! $e->retryable) {
                    break;
                }
                if ($attempt < $max) {
                    Sleep::for($e->retryAfter ?? min(30, 2 ** ($attempt - 1)))->seconds();
                }
            }
        }

        $status = match ($failure->kind) {
            'rate_limited' => 'rate_limited',
            'blocked' => 'blocked',
            default => 'error',
        };
        $this->record($purpose, $status, $attempt, $failure->status, $size, null, null, $started, $failure->getMessage(), $jobId);

        throw $failure;
    }

    /** What has been used today, for the dashboard and the check command. */
    public function usageToday(): array
    {
        $q = fn () => DB::table('ai_usage')->where('created_at', '>=', now()->startOfDay());

        return [
            'calls' => $q()->where('status', '!=', 'blocked')->count(),
            'ok' => $q()->where('status', 'ok')->count(),
            'failed' => $q()->whereIn('status', ['error', 'rate_limited'])->count(),
            'blocked' => $q()->where('status', 'blocked')->count(),
            'cost_usd' => round((float) $q()->sum('cost_usd'), 6),
            'cost_known' => $q()->where('status', 'ok')->whereNull('cost_usd')->doesntExist(),
        ];
    }

    private function limitReached(): ?string
    {
        $today = $this->usageToday();
        $calls = (int) config('job_intel.ai.daily_call_limit');
        $budget = (float) config('job_intel.ai.daily_budget_usd');

        return match (true) {
            $calls > 0 && $today['calls'] >= $calls => "Daily limit of {$calls} AI calls reached.",
            $budget > 0 && $today['cost_usd'] >= $budget => 'Daily AI budget of $'.number_format($budget, 2).' reached.',
            default => null,
        };
    }

    private function refuse(string $purpose, int $size, ?int $jobId, string $reason): never
    {
        $this->record($purpose, 'blocked', 0, null, $size, null, null, microtime(true), $reason, $jobId);

        throw new AiFailure($reason, kind: 'blocked');
    }

    private function record(string $purpose, string $status, int $attempts, ?int $http, int $size, ?int $in, ?int $out, float $started, ?string $error, ?int $jobId): void
    {
        $inPrice = (float) config('job_intel.ai.price_input_per_million');
        $outPrice = (float) config('job_intel.ai.price_output_per_million');
        $cost = $status === 'ok' && ($inPrice > 0 || $outPrice > 0) && $in !== null && $out !== null
            ? round(($in * $inPrice + $out * $outPrice) / 1_000_000, 6) : null;

        DB::table('ai_usage')->insert([
            'provider' => $this->provider->name(), 'model' => $this->provider->model(), 'purpose' => mb_substr($purpose, 0, 40),
            'status' => $status, 'attempts' => $attempts, 'http_status' => $http, 'input_chars' => $size,
            'input_tokens' => $in, 'output_tokens' => $out, 'cost_usd' => $cost,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'error' => $error === null ? null : mb_substr($error, 0, 200), 'job_id' => $jobId, 'created_at' => now(),
        ]);
    }
}
