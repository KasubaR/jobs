<?php

namespace Tests\Feature;

use App\Extraction\ExtractionPreview;
use App\Extraction\ExtractionRules;
use App\Extraction\Extractor;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Models\RawJobPost;
use App\Models\Source;
use App\Models\User;
use Database\Seeders\SourceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExtractionRulesTest extends TestCase
{
    use RefreshDatabase;

    private const RULES = [
        'title_separator' => ' at ',
        'date_formats' => ['j F Y', 'd/m/Y'],
        'fields' => [
            ['field' => 'closing_date', 'pattern' => 'closing date:\s*(.+)'],
            ['field' => 'location', 'pattern' => 'location:\s*([^\n]+)'],
            ['field' => 'employment_type', 'pattern' => 'contract type:\s*([^\n]+)'],
        ],
    ];

    private function storedAd(Source $source, string $title, ?string $content, ?string $url = null): RawJobPost
    {
        return RawJobPost::create([
            'source_id' => $source->id,
            'source_url' => $url ?? 'https://x.test/'.md5($title.$content),
            'raw_title' => $title,
            'raw_content' => $content,
            'content_hash' => hash('sha256', $title.$content),
            'fetched_at' => now(), 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    private function source(): Source
    {
        $this->seed(SourceSeeder::class);

        return Source::where('name', 'JobWeb Zambia')->first();
    }

    public function test_extracts_employer_dates_and_fields_from_a_stored_ad(): void
    {
        $post = $this->storedAd($this->source(), 'Budget Analyst at World Vision', '<p>Closing Date: 30 November 2026</p><p>Location: Lusaka</p><p>Contract type: Fixed term</p>');

        $r = app(Extractor::class)->extract($post, self::RULES);

        $this->assertSame('Budget Analyst', $r['title']);
        $this->assertSame('World Vision', $r['employer']);
        $this->assertSame('2026-11-30', $r['values']['closing_date']);
        $this->assertSame('Lusaka', $r['values']['location']);
        $this->assertSame('Fixed term', $r['values']['employment_type']);
        $this->assertSame([], $r['warnings']);
    }

    public function test_title_splits_on_the_last_separator(): void
    {
        $post = $this->storedAd($this->source(), 'Cashier at Branch Office at Stanbic Bank', null);

        $r = app(Extractor::class)->extract($post, self::RULES);

        $this->assertSame('Cashier at Branch Office', $r['title']);
        $this->assertSame('Stanbic Bank', $r['employer']);
    }

    public function test_missing_fields_are_null_and_unreadable_dates_are_flagged_not_guessed(): void
    {
        $post = $this->storedAd($this->source(), 'Driver at Sample Mining', '<p>Closing date: soon</p>');

        $r = app(Extractor::class)->extract($post, self::RULES);

        $this->assertNull($r['values']['closing_date']);
        $this->assertNull($r['values']['location']);
        $this->assertStringContainsString("Could not read the date 'soon'", implode(' ', $r['warnings']));
    }

    public function test_second_date_format_is_tried_and_impossible_dates_are_rejected(): void
    {
        $source = $this->source();
        $ok = $this->storedAd($source, 'A at B', 'Closing date: 05/12/2026');
        $bad = $this->storedAd($source, 'C at D', 'Closing date: 31/02/2026');

        $this->assertSame('2026-12-05', app(Extractor::class)->extract($ok, self::RULES)['values']['closing_date']);
        $this->assertNull(app(Extractor::class)->extract($bad, self::RULES)['values']['closing_date']);
    }

    public function test_an_ad_whose_raw_text_was_purged_still_yields_title_and_employer(): void
    {
        $post = $this->storedAd($this->source(), 'Nurse at Clinic', null);

        $r = app(Extractor::class)->extract($post, self::RULES);

        $this->assertSame('Clinic', $r['employer']);
        $this->assertStringContainsString('Raw text was removed', $r['warnings'][0]);
    }

    public function test_first_matching_rule_for_a_field_wins(): void
    {
        $post = $this->storedAd($this->source(), 'A at B', 'Deadline: 1 December 2026. Closing date: 9 December 2026');
        $rules = self::RULES;
        $rules['fields'] = [
            ['field' => 'closing_date', 'pattern' => 'deadline:\s*(\d+ \w+ \d{4})'],
            ['field' => 'closing_date', 'pattern' => 'closing date:\s*(\d+ \w+ \d{4})'],
        ];

        $this->assertSame('2026-12-01', app(Extractor::class)->extract($post, $rules)['values']['closing_date']);
    }

    public function test_unsafe_or_broken_patterns_are_rejected(): void
    {
        foreach (['', 'no group', '(unclosed', '(a+)+b', str_repeat('a', 201).'(x)'] as $pattern) {
            $this->assertNotNull(ExtractionRules::patternProblem($pattern), $pattern);
        }

        $this->assertNull(ExtractionRules::patternProblem('closing date:\s*(.+)'));
    }

    public function test_a_pattern_that_slips_through_cannot_hang_extraction(): void
    {
        // Not caught by the nested-repeat check, but still slow on this text; the backtrack limit stops it.
        $post = $this->storedAd($this->source(), 'A at B', str_repeat('a', 5000).'!');
        $rules = ['fields' => [['field' => 'location', 'pattern' => '(a|aa)*(a|aa)*(a|aa)*(a|aa)*z(x)']]];

        $start = microtime(true);
        $r = app(Extractor::class)->extract($post, $rules);

        $this->assertLessThan(5, microtime(true) - $start);
        $this->assertNull($r['values']['location']);
    }

    public function test_preview_reports_coverage_and_never_touches_the_network(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $source = $this->source();
        $this->storedAd($source, 'A at B', '<p>Closing date: 1 December 2026</p><p>Location: Ndola</p>');
        $this->storedAd($source, 'C at D', 'No details here');

        $preview = app(ExtractionPreview::class)->run($source, self::RULES);

        $this->assertSame(2, $preview['total']);
        $this->assertSame(2, $preview['coverage']['employer']);
        $this->assertSame(1, $preview['coverage']['closing_date']);
        $this->assertSame(1, $preview['coverage']['location']);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_preview_reports_problems_instead_of_running_bad_rules(): void
    {
        $source = $this->source();
        $this->storedAd($source, 'A at B', 'x');

        $preview = app(ExtractionPreview::class)->run($source, ['fields' => [['field' => 'location', 'pattern' => 'no group']]]);

        $this->assertNotEmpty($preview['problems']);
        $this->assertSame([], $preview['rows']);
    }

    // ---- the form ----

    private function openEdit(Source $source)
    {
        config(['job_intel.admin_email' => 'me@example.com']);
        $this->actingAs(User::factory()->create(['email' => 'me@example.com']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Livewire::test(EditSource::class, ['record' => $source->getRouteKey()]);
    }

    public function test_rules_typed_into_the_form_are_saved_on_the_source(): void
    {
        $source = $this->source();

        $this->openEdit($source)
            ->fillForm(['collector_config' => ['extraction' => self::RULES]])
            ->call('save')
            ->assertHasNoFormErrors();

        $saved = $source->fresh()->collector_config['extraction'];
        $this->assertSame(' at ', $saved['title_separator']);
        $this->assertSame('closing_date', $saved['fields'][0]['field']);
    }

    public function test_an_invalid_pattern_in_the_form_blocks_saving(): void
    {
        $source = $this->source();

        $this->openEdit($source)
            ->fillForm(['collector_config' => ['extraction' => ['fields' => [['field' => 'location', 'pattern' => '(a+)+b']]]]])
            ->call('save')
            ->assertHasFormErrors();

        $this->assertNull($source->fresh()->collector_config);
    }

    public function test_preview_action_can_be_opened_and_the_view_shows_the_results(): void
    {
        $source = $this->source();
        $this->storedAd($source, 'Budget Analyst at World Vision', 'Closing date: 30 November 2026');

        $this->openEdit($source)
            ->fillForm(['collector_config' => ['extraction' => self::RULES]])
            ->mountAction('previewExtraction')
            ->assertActionMounted('previewExtraction');

        $html = view('filament.sources.extraction-preview', [
            'preview' => app(ExtractionPreview::class)->run($source, self::RULES),
        ])->render();

        $this->assertStringContainsString('Budget Analyst', $html);
        $this->assertStringContainsString('World Vision', $html);
        $this->assertStringContainsString('2026-11-30', $html);
        $this->assertStringContainsString('Nothing was fetched', $html);
    }
}
