<?php

namespace Tests\Feature;

use App\Jobs\CollectSource;
use App\Models\RawJobPost;
use App\Models\Source;
use App\Models\SourceRun;
use App\Services\SourceManager;
use Database\Seeders\SourceSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class PythonWorkerTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
        Carbon::setTestNow('2026-11-08 12:00:00');
        Http::fake();
        $this->seed(SourceSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function python(array $attributes = [], bool $ready = true): Source
    {
        $source = Source::create(array_merge([
            'name' => 'JS Board', 'website_url' => 'https://js-board.test/', 'job_listing_url' => 'https://js-board.test/jobs',
            'source_type' => 'job_board', 'collection_method' => 'python', 'collector' => 'python_worker', 'collection_frequency' => 'every_6_hours',
            'access_status' => 'pending', 'is_active' => false, 'min_interval_seconds' => 3,
            'collector_config' => ['python' => ['script' => 'example_playwright', 'options' => ['max_pages' => 2]]],
        ], $attributes));

        if ($ready) {
            app(SourceManager::class)->decideAccess($source, 'approved', 'Owner approved.');
            app(SourceManager::class)->activate($source->fresh());
        }

        return $source->fresh();
    }

    private function file(string $content, string $name = 'ads.json'): string
    {
        $path = storage_path("framework/testing/py-{$name}");
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);

        return $path;
    }

    private function due(): array
    {
        Artisan::call('python:due', ['--json' => true]);

        return json_decode(trim(Artisan::output()), true);
    }

    // ---- what the worker asks ----

    public function test_due_lists_only_approved_active_python_sources_that_are_due(): void
    {
        $due = $this->python();
        $this->python(['name' => 'Not due', 'website_url' => 'https://b.test/'])->forceFill(['next_collection_at' => now()->addHours(2)])->save();
        $this->python(['name' => 'Pending', 'website_url' => 'https://c.test/'], ready: false);
        $this->python(['name' => 'Off', 'website_url' => 'https://d.test/'])->update(['is_active' => false]);
        Source::where('name', 'JobWeb Zambia')->first()->update(['access_status' => 'approved', 'is_active' => true]); // rss, not python

        $result = $this->due();

        $this->assertSame(['JS Board'], array_column($result['sources'], 'name'));
        $this->assertSame($due->id, $result['sources'][0]['id']);
    }

    public function test_due_gives_the_worker_its_rules_from_the_app(): void
    {
        $this->python();

        $result = $this->due();
        $source = $result['sources'][0];

        $this->assertStringStartsWith('ZamtrendsBot/', $result['user_agent']);
        $this->assertStringContainsString('owner@example.com', $result['user_agent']);
        $this->assertSame(5 * 1024 * 1024, $result['max_response_bytes']);
        $this->assertSame([3, 'example_playwright', 'https://js-board.test/jobs'], [$source['min_interval_seconds'], $source['script'], $source['listing_url']]);
        $this->assertSame(['max_pages' => 2], $source['options']);
    }

    public function test_options_are_always_a_json_object_even_when_empty(): void
    {
        $this->python(['collector_config' => ['python' => ['script' => 's', 'options' => []]]]);

        Artisan::call('python:due', ['--json' => true]);

        $this->assertStringContainsString('"options":{}', Artisan::output());
    }

    public function test_a_source_becomes_due_when_its_time_comes(): void
    {
        $source = $this->python();
        $source->forceFill(['next_collection_at' => now()->addHours(6)])->save();
        $this->assertSame([], $this->due()['sources']);

        Carbon::setTestNow(now()->addHours(6)->addMinute());
        $this->assertCount(1, $this->due()['sources']);
    }

    public function test_due_refuses_to_work_without_a_contact_address(): void
    {
        config(['job_intel.collector_contact' => null]);
        $this->python();

        $exit = Artisan::call('python:due', ['--json' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('COLLECTOR_CONTACT', Artisan::output());
    }

    public function test_due_has_a_readable_table_too(): void
    {
        $this->artisan('python:due')->expectsOutputToContain('No approved, active Python sources are due')->assertSuccessful();
        $this->python();
        $this->artisan('python:due')->expectsOutputToContain('JS Board')->assertSuccessful();
    }

    // ---- the app never fetches these itself ----

    public function test_the_scheduler_and_the_queue_leave_python_sources_alone(): void
    {
        Queue::fake();
        $source = $this->python();

        $this->artisan('collect:dispatch')->expectsOutputToContain('No approved, active sources are due')->assertSuccessful();
        Queue::assertNothingPushed();

        Queue::fake([]);
        CollectSource::dispatchSync($source->id, 'manual');
        $this->assertSame(0, SourceRun::count());
        Http::assertNothingSent();
    }

    public function test_collecting_a_python_source_in_the_app_explains_where_it_runs(): void
    {
        $this->python();

        $this->artisan('collect:run', ['source' => 'JS Board'])->expectsOutputToContain('Python worker on your computer')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_run_now_and_pasting_are_refused_and_hidden_for_python_sources(): void
    {
        $this->signIn();
        $source = $this->python();

        try {
            app(SourceManager::class)->runNow($source);
            $this->fail('Run now should be refused');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Python worker', $e->getMessage());
        }

        $this->post("/sources/{$source->id}/run")->assertSessionHas('error');
        $this->post("/sources/{$source->id}/ads", ['title' => 'x', 'content' => 'y'])->assertSessionHas('error');

        $this->get("/sources/{$source->id}")->assertOk()
            ->assertDontSee('Run now')->assertDontSee('Add ad')->assertDontSee('Import file')
            ->assertSee('collected by the Python worker on your computer');
        $this->assertSame(0, RawJobPost::count());
    }

    // ---- ads coming back ----

    public function test_an_import_from_the_worker_is_logged_as_python_and_reschedules_the_source(): void
    {
        $source = $this->python();
        $source->forceFill(['consecutive_failures' => 2])->save();
        $path = $this->file(json_encode([['title' => 'Cook at Cafe', 'url' => 'https://js-board.test/jobs/1', 'content' => 'Cook needed. hr@cafe.test']]));

        $this->artisan('collect:import', ['file' => $path, '--source' => 'JS Board', '--trigger' => 'python'])->assertSuccessful();

        $run = SourceRun::first();
        $this->assertSame(['success', 'python', 1], [$run->status, $run->trigger, $run->items_new]);
        $fresh = $source->fresh();
        $this->assertSame('2026-11-08 18:00:00', $fresh->next_collection_at->toDateTimeString());
        $this->assertSame(0, $fresh->consecutive_failures);
        $this->assertNotNull($fresh->last_collection_at);
        $this->assertStringNotContainsString('hr@cafe.test', RawJobPost::first()->raw_content);
        $this->assertSame([], $this->due()['sources']);
    }

    public function test_a_partial_import_still_counts_as_collected(): void
    {
        $source = $this->python();
        $path = $this->file(json_encode([['title' => 'Good', 'content' => 'a'], ['title' => '', 'content' => '']]));

        $this->artisan('collect:import', ['file' => $path, '--source' => 'JS Board', '--trigger' => 'python'])->assertSuccessful();

        $this->assertSame('partial', SourceRun::first()->status);
        $this->assertSame('2026-11-08 18:00:00', $source->fresh()->next_collection_at->toDateTimeString());
    }

    public function test_an_import_with_nothing_usable_backs_the_source_off(): void
    {
        $source = $this->python();
        $path = $this->file('[]');

        $this->artisan('collect:import', ['file' => $path, '--source' => 'JS Board', '--trigger' => 'python'])->assertFailed();

        $fresh = $source->fresh();
        $this->assertSame('failed', SourceRun::first()->status);
        $this->assertSame(1, $fresh->consecutive_failures);
        $this->assertSame('2026-11-08 13:00:00', $fresh->next_collection_at->toDateTimeString());
    }

    public function test_the_trigger_option_only_accepts_import_or_python(): void
    {
        $this->python();

        $this->artisan('collect:import', ['file' => $this->file('[]'), '--source' => 'JS Board', '--trigger' => 'scheduled'])
            ->expectsOutputToContain("--trigger must be 'import' or 'python'")->assertFailed();
    }

    public function test_manual_sources_are_still_not_scheduled_by_an_import(): void
    {
        $tsc = Source::where('name', 'Teaching Service Commission')->first();
        app(SourceManager::class)->decideAccess($tsc, 'approved', 'ok');
        app(SourceManager::class)->activate($tsc->fresh());

        $this->artisan('collect:import', ['file' => $this->file('[{"title":"A","content":"a"}]'), '--source' => 'Teaching Service Commission'])->assertSuccessful();

        $this->assertNull($tsc->fresh()->next_collection_at);
        $this->assertSame('import', SourceRun::first()->trigger);
    }

    public function test_a_python_source_does_not_take_files_while_not_approved_and_active(): void
    {
        $this->python([], ready: false);

        $this->artisan('collect:import', ['file' => $this->file('[{"title":"A","content":"a"}]'), '--source' => 'JS Board'])
            ->expectsOutputToContain('not approved and active')->assertFailed();
        $this->assertSame(0, RawJobPost::count());
    }

    // ---- failures the worker reports ----

    public function test_a_reported_failure_is_a_failed_run_that_backs_the_source_off(): void
    {
        $source = $this->python();

        $this->artisan('python:fail', ['source' => 'JS Board', 'message' => 'Script crashed: KeyError'])->expectsOutputToContain('failed run recorded')->assertSuccessful();

        $run = SourceRun::first();
        $this->assertSame(['failed', 'python'], [$run->status, $run->trigger]);
        $this->assertStringContainsString('Script crashed: KeyError', $run->error_message);
        $this->assertSame('other', $run->errors()->first()->stage);
        $this->assertSame(1, $source->fresh()->consecutive_failures);
        $this->assertSame('2026-11-08 13:00:00', $source->fresh()->next_collection_at->toDateTimeString());
    }

    public function test_a_blocked_report_says_the_worker_stopped_and_did_not_try_to_get_round_it(): void
    {
        $this->python();

        $this->artisan('python:fail', ['source' => 'JS Board', 'message' => 'CAPTCHA on /jobs', '--blocked' => true])->assertSuccessful();

        $message = SourceRun::first()->error_message;
        $this->assertStringContainsString('Blocked', $message);
        $this->assertStringContainsString('did not try to get round it', $message);
        $this->assertStringContainsString('CAPTCHA on /jobs', $message);
    }

    public function test_repeated_failures_switch_the_source_off_and_it_leaves_the_due_list(): void
    {
        $source = $this->python();

        for ($i = 0; $i < 5; $i++) {
            $this->artisan('python:fail', ['source' => (string) $source->id, 'message' => 'blocked', '--blocked' => true]);
        }

        $this->assertFalse($source->fresh()->is_active);
        $this->assertSame([], $this->due()['sources']);
    }

    public function test_a_failure_report_is_refused_for_other_sources(): void
    {
        $this->python([], ready: false);

        $this->artisan('python:fail', ['source' => 'JS Board', 'message' => 'x'])->expectsOutputToContain('not approved and active')->assertFailed();
        $this->artisan('python:fail', ['source' => 'JobWeb Zambia', 'message' => 'x'])->expectsOutputToContain('No Python-worker source')->assertFailed();
        $this->artisan('python:fail', ['source' => 'Nobody', 'message' => 'x'])->expectsOutputToContain('No Python-worker source')->assertFailed();
        $this->assertSame(0, SourceRun::count());
    }

    // ---- setting a source up ----

    private function form(Source $source, array $overrides = []): array
    {
        return $overrides + [
            'name' => $source->name, 'source_type' => 'job_board', 'website_url' => $source->website_url, 'job_listing_url' => $source->job_listing_url,
            'collection_method' => 'python', 'collector' => 'python_worker', 'collection_frequency' => 'every_6_hours', 'min_interval_seconds' => 3,
            'retain_raw' => '1', 'python' => ['script' => 'example_bs4', 'options' => '{"max_pages": 3}'],
        ];
    }

    public function test_the_form_saves_the_script_and_options(): void
    {
        $this->signIn();
        $source = $this->python([], ready: false);

        $this->put("/sources/{$source->id}", $this->form($source))->assertRedirect("/sources/{$source->id}");

        $saved = $source->fresh()->collector_config['python'];
        $this->assertSame(['example_bs4', ['max_pages' => 3]], [$saved['script'], $saved['options']]);

        $this->get("/sources/{$source->id}/edit")->assertOk()->assertSee('Python worker')->assertSee('example_bs4')->assertSee('max_pages');
    }

    public function test_script_names_that_could_point_elsewhere_are_refused(): void
    {
        $this->signIn();
        $source = $this->python([], ready: false);

        foreach (['', '../secret', 'Upper', '1abc', 'a b', 'x.py', 'a/b', str_repeat('a', 61)] as $bad) {
            $this->put("/sources/{$source->id}", $this->form($source, ['python' => ['script' => $bad, 'options' => '']]))
                ->assertSessionHasErrors('python');
        }
        $this->assertSame('example_playwright', $source->fresh()->collector_config['python']['script']);
    }

    public function test_options_must_be_a_small_json_object(): void
    {
        $this->signIn();
        $source = $this->python([], ready: false);

        foreach (['not json', '[1,2]', '"text"', '{"a":'.str_repeat('1', 2100).'}'] as $bad) {
            $this->put("/sources/{$source->id}", $this->form($source, ['python' => ['script' => 'ok', 'options' => $bad]]))->assertSessionHasErrors('python');
        }

        $this->put("/sources/{$source->id}", $this->form($source, ['python' => ['script' => 'ok', 'options' => '']]))->assertRedirect();
        $this->assertSame([], $source->fresh()->collector_config['python']['options']);
    }

    public function test_the_python_collector_needs_the_python_method(): void
    {
        $this->signIn();
        $source = $this->python([], ready: false);

        $this->put("/sources/{$source->id}", $this->form($source, ['collection_method' => 'rss']))->assertSessionHasErrors('collection_method');
    }

    public function test_a_python_source_without_a_script_cannot_be_switched_on(): void
    {
        $source = $this->python(['collector_config' => null], ready: false);
        app(SourceManager::class)->decideAccess($source, 'approved', 'ok');

        $problems = implode(' ', app(SourceManager::class)->activationProblems($source->fresh()));

        $this->assertStringContainsString('No Python script is named', $problems);
        $this->expectException(DomainException::class);
        app(SourceManager::class)->activate($source->fresh());
    }

    public function test_the_failure_and_run_pages_show_python_runs(): void
    {
        $this->signIn();
        $source = $this->python();
        $this->artisan('python:fail', ['source' => 'JS Board', 'message' => 'CAPTCHA on /jobs', '--blocked' => true]);

        $this->get('/runs')->assertOk()->assertSee('python')->assertSee('Blocked');
        $this->get("/sources/{$source->id}?tab=errors")->assertOk()->assertSee('CAPTCHA on /jobs');
    }
}
