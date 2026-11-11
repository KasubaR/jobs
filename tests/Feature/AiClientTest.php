<?php

namespace Tests\Feature;

use App\Ai\AiClient;
use App\Ai\AiFailure;
use App\Ai\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** The AI service door: switched off by default, safe with its key, retries what can pass, stops at its limits, records every call. No test reaches a real service. */
class AiClientTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'secret-test-key-abc123';

    private const URL = 'generativelanguage.googleapis.com/*';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config(['job_intel.ai' => array_replace(config('job_intel.ai'), [
            'enabled' => true, 'max_attempts' => 3, 'daily_call_limit' => 200, 'daily_budget_usd' => 1.0,
            'price_input_per_million' => 0, 'price_output_per_million' => 0,
            'gemini' => ['key' => self::KEY, 'model' => 'test-model', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta'],
        ])]);
    }

    private function client(): AiClient
    {
        return app(AiClient::class);
    }

    private function body(string $text = '{"ok":true}', int $in = 100, int $out = 20): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]], 'usageMetadata' => ['promptTokenCount' => $in, 'candidatesTokenCount' => $out]];
    }

    private function answer(string $text = '{"ok":true}', int $in = 100, int $out = 20)
    {
        return Http::response($this->body($text, $in, $out));
    }

    private function fails(callable $call): AiFailure
    {
        try {
            $call();
        } catch (AiFailure $e) {
            return $e;
        }
        $this->fail('Expected the AI request to fail.');
    }

    private function ask(string $prompt = 'Hello'): \App\Ai\AiResponse
    {
        return $this->client()->ask('Be brief.', $prompt, 'test');
    }

    // ---- off, and not set up ----

    public function test_it_is_off_unless_switched_on_and_sends_nothing(): void
    {
        Http::fake();
        config(['job_intel.ai.enabled' => false]);

        $e = $this->fails(fn () => $this->ask());

        $this->assertSame('off', $e->kind);
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('ai_usage')->count());
    }

    public function test_it_needs_a_key_and_a_model(): void
    {
        Http::fake();
        foreach (['key', 'model'] as $missing) {
            config(["job_intel.ai.gemini.{$missing}" => null]);
            $this->assertStringContainsString('not set up', $this->fails(fn () => $this->ask())->getMessage());
            config(['job_intel.ai.gemini.key' => self::KEY, 'job_intel.ai.gemini.model' => 'test-model']);
        }
        Http::assertNothingSent();
    }

    public function test_an_unknown_provider_is_refused_by_name(): void
    {
        config(['job_intel.ai.provider' => 'nope']);

        $this->expectExceptionMessage('Unknown AI_PROVIDER "nope"');
        app(AiProvider::class);
    }

    // ---- a good request ----

    public function test_the_key_goes_in_a_header_and_never_in_the_address_or_the_log(): void
    {
        Http::fake([self::URL => $this->answer()]);

        $r = $this->ask('Read this ad: Accountant wanted.');

        $this->assertSame(['ok' => true], $r->json());
        Http::assertSent(function (Request $request) {
            return $request->hasHeader('x-goog-api-key', self::KEY)
                && ! str_contains($request->url(), self::KEY)
                && str_contains($request->url(), '/models/test-model:generateContent')
                && $request['generationConfig']['responseMimeType'] === 'application/json'
                && $request['systemInstruction']['parts'][0]['text'] === 'Be brief.';
        });
        $row = json_encode(DB::table('ai_usage')->first());
        $this->assertStringNotContainsString(self::KEY, $row);
        $this->assertStringNotContainsString('Accountant wanted', $row);   // sizes only, never the ad
    }

    public function test_it_records_tokens_and_works_out_the_cost_from_the_configured_prices(): void
    {
        Http::fake([self::URL => $this->answer(in: 1000, out: 500)]);
        config(['job_intel.ai.price_input_per_million' => 0.5, 'job_intel.ai.price_output_per_million' => 2.0]);

        $this->ask();

        $row = DB::table('ai_usage')->first();
        $this->assertSame(['gemini', 'test-model', 'test', 'ok', 1], [$row->provider, $row->model, $row->purpose, $row->status, (int) $row->attempts]);
        $this->assertSame([1000, 500], [(int) $row->input_tokens, (int) $row->output_tokens]);
        $this->assertEqualsWithDelta(0.0015, (float) $row->cost_usd, 1e-9);   // 1000*0.5/1e6 + 500*2/1e6
    }

    public function test_without_prices_the_cost_is_left_blank_not_guessed(): void
    {
        Http::fake([self::URL => $this->answer()]);

        $this->ask();

        $this->assertNull(DB::table('ai_usage')->value('cost_usd'));
        $this->assertFalse($this->client()->usageToday()['cost_known']);
    }

    public function test_an_answer_that_is_not_json_is_returned_as_text_with_no_json(): void
    {
        Http::fake([self::URL => $this->answer('not json at all')]);

        $r = $this->ask();

        $this->assertSame('not json at all', $r->text);
        $this->assertNull($r->json());
    }

    // ---- what is retried ----

    public function test_a_rate_limit_is_waited_out_using_the_retry_after_and_tried_again(): void
    {
        Http::fake([self::URL => Http::sequence()->push(['error' => ['message' => 'quota']], 429, ['Retry-After' => '7'])->push($this->body())]);

        $this->ask();

        Sleep::assertSequence([Sleep::for(7)->seconds()]);
        $row = DB::table('ai_usage')->first();
        $this->assertSame(['ok', 2], [$row->status, (int) $row->attempts]);
    }

    public function test_a_server_error_is_retried_with_longer_waits_then_given_up(): void
    {
        Http::fake([self::URL => Http::response('boom', 503)]);

        $e = $this->fails(fn () => $this->ask());

        $this->assertStringContainsString('503', $e->getMessage());
        Http::assertSentCount(3);
        Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(2)->seconds()]);
        $row = DB::table('ai_usage')->first();
        $this->assertSame(['error', 3, 503], [$row->status, (int) $row->attempts, (int) $row->http_status]);
    }

    public function test_a_timeout_is_retried(): void
    {
        $calls = 0;
        Http::fake([self::URL => function () use (&$calls) {
            if (++$calls < 3) {
                throw new ConnectionException('cURL error 28: timed out');
            }

            return Http::response($this->body());
        }]);

        $this->ask();

        $this->assertSame(3, $calls);
        $this->assertSame(3, (int) DB::table('ai_usage')->value('attempts'));
    }

    public function test_a_network_failure_says_why_without_the_key_or_the_address(): void
    {
        Http::fake([self::URL => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://generativelanguage.googleapis.com/v1beta/models/test-model:generateContent?key='.self::KEY)]);

        $e = $this->fails(fn () => $this->ask());

        $this->assertStringContainsString('cURL error 28: Operation timed out after 30001 milliseconds', $e->getMessage());
        $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        $this->assertStringNotContainsString('curl.haxx.se', $e->getMessage());
        $this->assertStringNotContainsString(self::KEY, json_encode(DB::table('ai_usage')->get()));
    }

    public function test_a_refused_key_is_not_retried_and_the_message_does_not_contain_the_key(): void
    {
        Http::fake([self::URL => Http::response(['error' => ['message' => 'API key not valid: '.self::KEY]], 403)]);

        $e = $this->fails(fn () => $this->ask());

        Http::assertSentCount(1);
        $this->assertStringContainsString('GEMINI_API_KEY', $e->getMessage());
        $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        $this->assertStringNotContainsString(self::KEY, json_encode(DB::table('ai_usage')->get()));
        Sleep::assertNeverSlept();
    }

    public function test_a_bad_request_is_not_retried(): void
    {
        Http::fake([self::URL => Http::response(['error' => ['message' => 'bad model']], 400)]);

        $e = $this->fails(fn () => $this->ask());

        Http::assertSentCount(1);
        $this->assertStringContainsString('400', $e->getMessage());
    }

    public function test_a_declined_text_is_recorded_and_not_retried(): void
    {
        Http::fake([self::URL => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']])]);

        $e = $this->fails(fn () => $this->ask());

        Http::assertSentCount(1);
        $this->assertSame('blocked', $e->kind);
        $this->assertSame('blocked', DB::table('ai_usage')->value('status'));
    }

    public function test_an_empty_answer_is_a_failure(): void
    {
        Http::fake([self::URL => Http::response(['candidates' => []])]);

        $this->assertStringContainsString('no text', $this->fails(fn () => $this->ask())->getMessage());
    }

    // ---- limits ----

    public function test_a_text_over_the_size_limit_is_refused_not_cut(): void
    {
        Http::fake();
        config(['job_intel.ai.max_input_chars' => 50]);

        $e = $this->fails(fn () => $this->ask(str_repeat('x', 100)));

        $this->assertSame('blocked', $e->kind);
        Http::assertNothingSent();
        $this->assertSame('blocked', DB::table('ai_usage')->value('status'));
    }

    public function test_the_size_limit_is_on_the_advert_not_on_the_fixed_instructions(): void
    {
        Http::fake([self::URL => $this->answer()]);
        config(['job_intel.ai.max_input_chars' => 100]);

        // 300 characters of instructions plus a 60-character advert: the advert is under the limit, the total is not
        $this->client()->ask(str_repeat('i', 300), str_repeat('x', 60), 'test');

        Http::assertSentCount(1);
        $this->assertSame(360, (int) DB::table('ai_usage')->value('input_chars'));   // the whole request is still what is recorded
    }

    public function test_the_daily_call_limit_stops_further_calls(): void
    {
        Http::fake([self::URL => $this->answer()]);
        config(['job_intel.ai.daily_call_limit' => 2]);

        $this->ask();
        $this->ask();
        $e = $this->fails(fn () => $this->ask());

        $this->assertStringContainsString('Daily limit of 2', $e->getMessage());
        Http::assertSentCount(2);
        $this->assertSame(1, DB::table('ai_usage')->where('status', 'blocked')->count());
    }

    public function test_yesterdays_calls_do_not_count_against_today(): void
    {
        Http::fake([self::URL => $this->answer()]);
        config(['job_intel.ai.daily_call_limit' => 1]);
        $this->ask();
        DB::table('ai_usage')->update(['created_at' => now()->subDay()]);

        $this->ask();

        Http::assertSentCount(2);
    }

    public function test_the_daily_budget_stops_further_calls(): void
    {
        Http::fake([self::URL => $this->answer(in: 1_000_000, out: 0)]);
        config(['job_intel.ai.price_input_per_million' => 0.6, 'job_intel.ai.daily_budget_usd' => 1.0]);

        $this->ask();   // $0.60
        $this->ask();   // $1.20 in total: over the budget
        $e = $this->fails(fn () => $this->ask());

        $this->assertStringContainsString('budget', $e->getMessage());
        Http::assertSentCount(2);
    }

    // ---- the check command ----

    public function test_the_check_command_shows_settings_never_the_key_and_sends_nothing_without_call(): void
    {
        Http::fake();

        $this->artisan('ai:check')->expectsOutputToContain('Key set: yes')->expectsOutputToContain('Model: test-model')->doesntExpectOutputToContain(self::KEY)->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_check_command_with_call_tests_the_connection(): void
    {
        Http::fake([self::URL => $this->answer(in: 12, out: 4)]);

        $this->artisan('ai:check', ['--call' => true])->expectsOutputToContain('Tokens in/out: 12 / 4')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_the_check_command_reports_a_failed_connection(): void
    {
        Http::fake([self::URL => Http::response('no', 403)]);

        $this->artisan('ai:check', ['--call' => true])->expectsOutputToContain('GEMINI_API_KEY')->assertFailed();
    }
}
