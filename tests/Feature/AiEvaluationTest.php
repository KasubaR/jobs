<?php

namespace Tests\Feature;

use App\Ai\Evaluation\AdvertSet;
use App\Ai\Evaluation\Scorer;
use App\Ai\Extraction\QualificationSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** The evaluation command and the scoring behind it. No test reaches a real AI service. */
class AiEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'generativelanguage.googleapis.com/*';

    private array $existingReports = [];

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config(['job_intel.ai' => array_replace(config('job_intel.ai'), [
            'enabled' => true, 'max_attempts' => 1, 'daily_call_limit' => 500, 'daily_budget_usd' => 0, 'max_input_chars' => 20000,
            'price_input_per_million' => 0.5, 'price_output_per_million' => 2.0,
            'gemini' => ['key' => 'test-key', 'model' => 'test-model', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta'],
        ])]);
        $this->existingReports = glob(storage_path('app/ai-evaluations/*.json')) ?: [];
    }

    protected function tearDown(): void
    {
        foreach (array_diff(glob(storage_path('app/ai-evaluations/*.json')) ?: [], $this->existingReports) as $new) {
            File::delete($new);
        }
        parent::tearDown();
    }

    private function newReports(): array
    {
        return array_values(array_diff(glob(storage_path('app/ai-evaluations/*.json')) ?: [], $this->existingReports));
    }

    /** The full answer shape for an advert's written answer. */
    private function full(array $expected): array
    {
        return $expected + ['skills' => [], 'other_requirements' => [], 'extraction_notes' => []];
    }

    /** A model that answers every advert with $answer(expected), found by looking for the advert's text in the request. */
    private function model(callable $answer): void
    {
        $adverts = collect((new AdvertSet)->all());

        Http::fake([self::URL => function (Request $request) use ($adverts, $answer) {
            $prompt = $request['contents'][0]['parts'][0]['text'];
            $ad = $adverts->first(fn ($a) => str_contains($prompt, trim($a['text'])));

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($answer($this->full($ad['expected']), $ad))]]]]], 'usageMetadata' => ['promptTokenCount' => 3000, 'candidatesTokenCount' => 400]]);
        }]);
    }

    private function tuneCount(): int
    {
        return count((new AdvertSet)->split('tune'));
    }

    // ---- who may be scored, and how often ----

    public function test_the_hold_out_needs_final_and_is_scored_once_per_prompt_version(): void
    {
        $this->model(fn ($e) => $e);

        $this->artisan('ai:evaluate', ['--split' => 'holdout', '--yes' => true])->expectsOutputToContain('Add --final')->assertFailed();
        Http::assertNothingSent();

        $this->artisan('ai:evaluate', ['--split' => 'holdout', '--final' => true, '--yes' => true])->assertSuccessful();
        $sent = count(Http::recorded());
        $this->assertSame(count((new AdvertSet)->split('holdout')), $sent);

        $this->artisan('ai:evaluate', ['--split' => 'holdout', '--final' => true, '--yes' => true])->expectsOutputToContain('already been scored')->assertFailed();
        $this->assertCount($sent, Http::recorded());

        $this->artisan('ai:evaluate', ['--split' => 'holdout', '--final' => true, '--again' => true, '--yes' => true])->assertSuccessful();
        $this->assertGreaterThan($sent, count(Http::recorded()));
    }

    public function test_a_full_run_also_uses_up_the_hold_out(): void
    {
        $this->model(fn ($e) => $e);
        $this->artisan('ai:evaluate', ['--split' => 'all', '--final' => true, '--yes' => true])->assertSuccessful();

        $this->artisan('ai:evaluate', ['--split' => 'holdout', '--final' => true, '--yes' => true])->expectsOutputToContain('already been scored')->assertFailed();
    }

    public function test_a_wrong_split_is_refused(): void
    {
        $this->artisan('ai:evaluate', ['--split' => 'everything'])->expectsOutputToContain('tune, holdout or all')->assertFailed();
    }

    // ---- asking first ----

    public function test_a_dry_run_shows_the_size_and_cost_and_sends_nothing(): void
    {
        Http::fake();

        $this->artisan('ai:evaluate', ['--dry-run' => true])
            ->expectsOutputToContain($this->tuneCount().' adverts (tune), prompt version 2')->expectsOutputToContain('Rough cost: $')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame([], $this->newReports());
    }

    public function test_it_says_when_no_prices_are_set(): void
    {
        config(['job_intel.ai.price_input_per_million' => 0, 'job_intel.ai.price_output_per_million' => 0]);

        $this->artisan('ai:evaluate', ['--dry-run' => true])->expectsOutputToContain('cost cannot be worked out')->assertSuccessful();
    }

    public function test_it_warns_about_adverts_over_the_size_limit(): void
    {
        config(['job_intel.ai.max_input_chars' => 5000]);

        $this->artisan('ai:evaluate', ['--dry-run' => true, '--split' => 'all', '--final' => true])->expectsOutputToContain('longer than AI_MAX_INPUT_CHARS')->assertSuccessful();
    }

    public function test_it_asks_first_and_sends_nothing_if_told_no(): void
    {
        Http::fake();

        $this->artisan('ai:evaluate')->expectsConfirmation('Send these adverts to the AI provider now?', 'no')->expectsOutputToContain('Nothing sent')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_refuses_when_the_ai_is_switched_off(): void
    {
        Http::fake();
        config(['job_intel.ai.enabled' => false]);

        $this->artisan('ai:evaluate', ['--yes' => true])->expectsOutputToContain('switched off')->assertFailed();

        Http::assertNothingSent();
    }

    // ---- running ----

    public function test_a_perfect_model_scores_one_hundred_per_cent_and_everything_is_recorded(): void
    {
        $this->model(fn ($e) => $e);

        $this->artisan('ai:evaluate', ['--yes' => true])
            ->expectsOutputToContain('Precision 100%   Recall 100%   Right strength 100%')->assertSuccessful();

        Http::assertSentCount($this->tuneCount());
        $usage = DB::table('ai_usage')->where('purpose', 'evaluate')->get();
        $this->assertCount($this->tuneCount(), $usage);
        $this->assertSame([QualificationSchema::PROMPT_VERSION], $usage->pluck('prompt_version')->map(fn ($v) => (int) $v)->unique()->values()->all());

        $report = json_decode(File::get($this->newReports()[0]), true);
        $this->assertSame([QualificationSchema::PROMPT_VERSION, 'test-model', 'tune'], [$report['prompt_version'], $report['model'], $report['split']]);
        $this->assertSame(1.0, (float) $report['summary']['total']['precision']);
        $this->assertSame($this->tuneCount(), $report['usage']['calls']);
        $this->assertEquals($this->tuneCount() * (3000 * 0.5 + 400 * 2.0) / 1_000_000, $report['usage']['cost_usd']);   // the cost is worked out from the real token counts
        $this->assertCount($this->tuneCount(), $report['adverts']);
    }

    public function test_adverts_with_several_positions_are_only_run_when_asked_and_are_not_scored(): void
    {
        $this->model(fn ($e) => $e);
        $this->artisan('ai:evaluate', ['--yes' => true, '--with-multi' => true])->expectsOutputToContain('several positions: not scored')->assertSuccessful();

        Http::assertSentCount(count((new AdvertSet)->split('tune', true)));
        $this->assertSame(2, count((new AdvertSet)->split('tune', true)) - $this->tuneCount());   // Multiple Positions and Lecturers
    }

    public function test_one_advert_can_be_run_by_its_number(): void
    {
        $this->model(fn ($e) => $e);

        $this->artisan('ai:evaluate', ['--yes' => true, '--only' => '9'])->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_contains($r['contents'][0]['parts'][0]['text'], 'Degree in Accounts or Equivalent'));
    }

    public function test_a_model_that_finds_nothing_scores_zero_recall_and_the_misses_are_listed(): void
    {
        $this->model(fn ($e) => ['qualification_found' => false, 'qualifications' => [], 'professional_registrations' => [], 'experience_requirements' => [], 'skills' => [], 'other_requirements' => [], 'extraction_notes' => []]);

        $this->artisan('ai:evaluate', ['--yes' => true])->expectsOutputToContain('Recall   0%')->expectsOutputToContain('MISSED')->assertSuccessful();
    }

    public function test_an_invented_item_is_rejected_by_the_checker_and_reported(): void
    {
        $this->model(function ($e) {
            $e['qualifications'][] = ['name' => 'Master of Laws', 'level' => 'masters', 'field' => 'law', 'requirement_type' => 'mandatory', 'alternative_group' => null, 'original_text' => 'A Master of Laws is required.', 'confidence' => 0.9];

            return $e;
        });

        $this->artisan('ai:evaluate', ['--yes' => true, '--only' => '9'])
            ->expectsOutputToContain('REJECTED BY THE CHECKER')->expectsOutputToContain('The quoted wording is not in the advert.')->expectsOutputToContain('Precision 100%')->assertSuccessful();
    }

    public function test_a_model_that_overstates_a_strength_is_caught(): void
    {
        // advert 10: ACCA or CIMA "will be an advantage"; the checker lowers a mandatory back, so the model changes the quote's meaning by quoting less
        $this->model(function ($e, $ad) {
            foreach ($e['professional_registrations'] as &$r) {
                $r['requirement_type'] = 'mandatory';
                $r['original_text'] = 'ACCA or CIMA';   // a shorter quote without the cue: the checker cannot lower it
            }

            return $e;
        });

        $this->artisan('ai:evaluate', ['--yes' => true, '--only' => '10'])->expectsOutputToContain('OVERSTATED')->assertSuccessful();
    }

    public function test_a_refused_key_stops_the_run_after_the_first_advert(): void
    {
        Http::fake([self::URL => Http::response(['error' => ['message' => 'bad key']], 403)]);

        $this->artisan('ai:evaluate', ['--yes' => true])->expectsOutputToContain('Stopping')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_the_daily_limit_stops_the_run_and_says_so(): void
    {
        $this->model(fn ($e) => $e);
        config(['job_intel.ai.daily_call_limit' => 2]);

        $this->artisan('ai:evaluate', ['--yes' => true])->expectsOutputToContain('Daily limit of 2')->expectsOutputToContain('Stopping')->assertSuccessful();

        Http::assertSentCount(2);
    }

    public function test_an_answer_that_is_not_json_is_reported_and_the_run_goes_on(): void
    {
        Http::fake([self::URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Sorry, I cannot do that.']]]]]])]);

        $this->artisan('ai:evaluate', ['--yes' => true, '--only' => '9'])->expectsOutputToContain('answer refused')->assertSuccessful();
    }

    // ---- the scoring ----

    private function q(string $name, ?string $level, string $type, string $quote): array
    {
        return ['name' => $name, 'level' => $level, 'field' => null, 'requirement_type' => $type, 'alternative_group' => null, 'original_text' => $quote, 'confidence' => 1.0];
    }

    private function r(string $name, string $type, string $quote): array
    {
        return ['name' => $name, 'requirement_type' => $type, 'alternative_group' => null, 'original_text' => $quote, 'confidence' => 1.0];
    }

    private function e(int $min, ?int $max, string $type, string $quote): array
    {
        return ['years_min' => $min, 'years_max' => $max, 'area' => null, 'requirement_type' => $type, 'alternative_group' => null, 'original_text' => $quote, 'confidence' => 1.0];
    }

    private function answer(array $sections = []): array
    {
        return array_replace(['qualification_found' => ! empty($sections['qualifications']), 'qualifications' => [], 'professional_registrations' => [], 'experience_requirements' => []], $sections);
    }

    public function test_the_same_answer_is_a_perfect_score(): void
    {
        $a = $this->answer(['qualifications' => [$this->q('Diploma in Nursing', 'diploma', 'mandatory', 'Must hold a Diploma in Nursing.')], 'experience_requirements' => [$this->e(3, null, 'mandatory', 'At least 3 years')]]);

        $s = (new Scorer)->summarise([['sector' => 'healthcare', 'score' => (new Scorer)->score($a, $a)]]);

        $this->assertSame([1.0, 1.0, 1.0], [$s['total']['precision'], $s['total']['recall'], $s['total']['strength']]);
    }

    public function test_a_reworded_name_or_a_short_form_of_a_body_still_matches(): void
    {
        $want = $this->answer([
            'qualifications' => [$this->q('Diploma in Biomedical Sciences', 'diploma', 'mandatory', 'A Diploma in Biomedical Sciences with at least three years.')],
            'professional_registrations' => [$this->r('Health Professions Council of Zambia (HPCZ)', 'mandatory', 'A valid practicing license with the Health Professions Council of Zambia (HPCZ).')],
        ]);
        $got = $this->answer([
            'qualifications' => [$this->q('Biomedical Sciences Diploma', 'diploma', 'mandatory', 'A Diploma in Biomedical Sciences')],
            'professional_registrations' => [$this->r('HPCZ', 'mandatory', 'valid practicing license with the Health Professions Council of Zambia (HPCZ)')],
        ]);

        $r = (new Scorer)->score($want, $got);

        $this->assertCount(1, $r['sections']['qualifications']['matched']);
        $this->assertCount(1, $r['sections']['professional_registrations']['matched']);
    }

    public function test_the_same_level_from_another_line_is_not_the_same_item(): void
    {
        $want = $this->answer(['qualifications' => [$this->q('Diploma in Nursing', 'diploma', 'mandatory', 'Must hold a Diploma in Nursing.')]]);
        $got = $this->answer(['qualifications' => [$this->q('Diploma in Welding', 'diploma', 'mandatory', 'A Diploma in Welding is needed.')]]);

        $r = (new Scorer)->score($want, $got)['sections']['qualifications'];

        $this->assertSame([0, 1, 1], [count($r['matched']), count($r['missed']), count($r['extra'])]);
    }

    public function test_strength_errors_are_told_apart_and_counted(): void
    {
        $want = $this->answer(['professional_registrations' => [
            $this->r('ACCA', 'advantage', 'ACCA is an added advantage'), $this->r('CIMA', 'mandatory', 'CIMA is required'), $this->r('ZICA', 'preferred', 'ZICA is preferred'),
        ]]);
        $got = $this->answer(['professional_registrations' => [
            $this->r('ACCA', 'mandatory', 'ACCA is an added advantage'), $this->r('CIMA', 'advantage', 'CIMA is required'), $this->r('ZICA', 'preferred', 'ZICA is preferred'),
        ]]);

        $s = (new Scorer)->summarise([['sector' => 'finance', 'score' => (new Scorer)->score($want, $got)]]);

        $this->assertSame([3, 1, 1, 1], [$s['total']['matched'], $s['total']['overstated'], $s['total']['understated'], $s['total']['strength_ok']]);
        $this->assertSame(0.333, $s['total']['strength']);
    }

    public function test_missed_and_extra_items_and_a_wrongly_found_flag_are_counted(): void
    {
        $want = $this->answer(['qualifications' => [$this->q('Diploma', 'diploma', 'mandatory', 'Diploma in X')]]);
        $got = $this->answer(['qualification_found' => true, 'professional_registrations' => [$this->r('ZICA', 'mandatory', 'ZICA member')]]);
        $none = $this->answer();

        $s = (new Scorer)->summarise([
            ['sector' => 'finance', 'score' => (new Scorer)->score($want, $got)],
            ['sector' => 'finance', 'score' => (new Scorer)->score($none, $this->answer(['qualifications' => [$this->q('Invented', 'bachelor', 'mandatory', 'Invented')]]))],
        ]);

        $this->assertSame(['expected' => 1, 'got' => 2, 'matched' => 0], array_intersect_key($s['total'], array_flip(['expected', 'got', 'matched'])));
        $this->assertSame(0.0, $s['total']['precision']);
        $this->assertSame(1, $s['qualification_found']['invented']);
        $this->assertSame(1, $s['qualification_found']['both_yes']);
    }

    public function test_nothing_expected_and_nothing_given_has_no_division_by_zero(): void
    {
        $s = (new Scorer)->summarise([['sector' => 'finance', 'score' => (new Scorer)->score($this->answer(), $this->answer())]]);

        $this->assertNull($s['total']['precision']);
        $this->assertNull($s['total']['recall']);
        $this->assertSame(1, $s['qualification_found']['both_no']);
    }
}
