<?php

namespace Tests\Feature;

use App\Models\RawJobPost;
use App\Models\Source;
use App\Models\SourceAccessLog;
use App\Models\SourceRun;
use App\Models\User;
use App\Services\SourceManager;
use Database\Seeders\SourceSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class SourceManagementTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->admin = $this->signIn();
        $this->seed(SourceSeeder::class);
    }

    private function jobweb(): Source
    {
        return Source::where('name', 'JobWeb Zambia')->first();
    }

    private function validForm(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Example Mining Careers',
            'source_type' => 'company',
            'website_url' => 'https://careers.example.com',
            'job_listing_url' => '',
            'collection_method' => 'manual',
            'collector' => '',
            'collection_frequency' => 'weekly',
            'min_interval_seconds' => 2,
            'retain_raw' => '1',
            'notes' => '',
        ];
    }

    // ---- rules (SourceManager) ----

    public function test_cannot_activate_a_source_that_is_not_approved(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Access is 'pending'");

        app(SourceManager::class)->activate($this->jobweb());
    }

    public function test_cannot_activate_an_approved_source_without_a_collector(): void
    {
        $source = Source::where('name', 'Job Search Zambia')->first();
        app(SourceManager::class)->decideAccess($source, 'approved', 'Owner checked the terms.');

        $this->assertStringContainsString('No collector is assigned', implode(' ', app(SourceManager::class)->activationProblems($source->fresh())));
        $this->expectException(DomainException::class);
        app(SourceManager::class)->activate($source->fresh());
    }

    public function test_deciding_access_needs_a_valid_status_but_no_note(): void
    {
        try {
            app(SourceManager::class)->decideAccess($this->jobweb(), 'maybe', 'note');
            $this->fail('Expected a DomainException.');
        } catch (DomainException) {
            $this->assertSame('pending', $this->jobweb()->access_status);
        }

        app(SourceManager::class)->decideAccess($this->jobweb(), 'approved', '  ');
        $this->assertSame('approved', $this->jobweb()->access_status);
        $this->assertNull($this->jobweb()->access_decision_note);
    }

    public function test_approving_and_activating_are_logged_with_the_user_and_note(): void
    {
        $manager = app(SourceManager::class);
        $manager->decideAccess($this->jobweb(), 'approved', 'Read the privacy and DMCA pages; no scraping clause.');
        $manager->activate($this->jobweb());

        $logs = SourceAccessLog::where('source_id', $this->jobweb()->id)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($this->admin->id, $logs[0]->user_id);
        $this->assertSame('Read the privacy and DMCA pages; no scraping clause.', $logs[0]->note);
        $this->assertTrue($this->jobweb()->is_active);
    }

    public function test_moving_an_active_source_away_from_approved_switches_it_off(): void
    {
        $manager = app(SourceManager::class);
        $manager->decideAccess($this->jobweb(), 'approved', 'ok');
        $manager->activate($this->jobweb());

        $manager->decideAccess($this->jobweb(), 'restricted', 'They emailed asking us to stop.');

        $this->assertFalse($this->jobweb()->is_active);
        $this->assertSame('restricted', $this->jobweb()->access_status);
    }

    public function test_a_source_with_stored_ads_cannot_be_deleted(): void
    {
        $source = $this->jobweb();
        $this->assertTrue(app(SourceManager::class)->canBeDeleted($source));

        RawJobPost::create([
            'source_id' => $source->id, 'source_url' => 'https://x.test/1', 'content_hash' => str_repeat('a', 64),
            'fetched_at' => now(), 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->assertFalse(app(SourceManager::class)->canBeDeleted($source));
    }

    // ---- screens ----

    public function test_dashboard_runs_and_sources_pages_render(): void
    {
        SourceRun::create(['source_id' => $this->jobweb()->id, 'started_at' => now(), 'finished_at' => now(), 'status' => 'failed', 'error_message' => 'Site returned HTTP 503']);

        $this->get('/')->assertOk()->assertSee('Welcome back')->assertSee('Collection activity');
        $this->get('/sources')->assertOk()->assertSee('JobWeb Zambia')->assertSee('UN Jobs');
        $this->get('/runs')->assertOk()->assertSee('Site returned HTTP 503');
        $this->get('/runs?status=success')->assertOk()->assertDontSee('Site returned HTTP 503');
    }

    public function test_source_list_filters(): void
    {
        $this->jobweb()->forceFill(['consecutive_failures' => 2])->save();

        $this->get('/sources?failing=1')->assertOk()->assertSee('JobWeb Zambia')->assertDontSee('UN Jobs');
        $this->get('/sources?access=restricted')->assertOk()->assertSee('UN Jobs')->assertDontSee('JobWeb Zambia');
        $this->get('/sources?q=linked')->assertOk()->assertSee('LinkedIn Jobs')->assertDontSee('JobWeb Zambia');
        $this->get('/sources?access=bogus')->assertSessionHasErrors('access');
    }

    public function test_search_text_cannot_act_as_a_wildcard(): void
    {
        $this->get('/sources?q=%25')->assertOk()->assertDontSee('JobWeb Zambia');
    }

    public function test_source_page_shows_history_errors_and_access_log_tabs(): void
    {
        $source = $this->jobweb();
        SourceRun::create(['source_id' => $source->id, 'started_at' => now(), 'finished_at' => now(), 'status' => 'success']);
        SourceRun::create(['source_id' => $source->id, 'started_at' => now(), 'finished_at' => now(), 'status' => 'failed', 'error_message' => 'Site returned HTTP 503']);
        $this->get("/sources/{$source->id}")->assertOk()->assertSee('Overview')->assertSee('Cannot be switched on yet');
        app(SourceManager::class)->decideAccess($source, 'approved', 'Checked the privacy policy.');
        $this->get("/sources/{$source->id}?tab=runs")->assertOk()->assertSee('success')->assertSee('failed');
        $this->get("/sources/{$source->id}?tab=errors")->assertOk()->assertSee('Site returned HTTP 503');
        $this->get("/sources/{$source->id}?tab=access")->assertOk()->assertSee('Checked the privacy policy.')->assertSee('me@example.com');
    }

    public function test_decide_access_records_the_decision_without_a_note(): void
    {
        $source = $this->jobweb();

        $this->post("/sources/{$source->id}/access", ['status' => 'approved'])->assertSessionHas('status');
        $this->assertSame('approved', $this->jobweb()->access_status);
        $this->assertNull($this->jobweb()->access_decision_note);

        $this->post("/sources/{$source->id}/access", ['status' => 'approved', 'note' => 'Checked terms today.'])->assertSessionHas('status');
        $this->assertSame('Checked terms today.', $this->jobweb()->access_decision_note);

        $this->post("/sources/{$source->id}/access", ['status' => 'hacked', 'note' => 'x'])->assertSessionHasErrors('status');
    }

    public function test_activate_is_refused_for_an_unapproved_source_and_works_once_approved(): void
    {
        $source = $this->jobweb();

        $this->post("/sources/{$source->id}/activate")->assertSessionHas('error');
        $this->assertFalse($this->jobweb()->is_active);

        app(SourceManager::class)->decideAccess($source, 'approved', 'ok');
        $this->post("/sources/{$source->id}/activate")->assertSessionHas('status');
        $this->assertTrue($this->jobweb()->is_active);

        $this->post("/sources/{$source->id}/deactivate")->assertSessionHas('status');
        $this->assertFalse($this->jobweb()->is_active);
    }

    public function test_test_access_makes_requests_but_stores_nothing(): void
    {
        Http::fake(['*/robots.txt' => Http::response('', 404), '*' => Http::response(file_get_contents(base_path('tests/Fixtures/jobweb-feed.xml')), 200)]);

        $this->post("/sources/{$this->jobweb()->id}/test")->assertSessionHas('status');

        $this->assertSame(0, RawJobPost::count());
        $this->assertSame(0, SourceRun::count());
    }

    public function test_test_access_is_refused_for_restricted_sources_without_any_request(): void
    {
        Http::fake();
        $un = Source::where('name', 'UN Jobs')->first();

        $this->post("/sources/{$un->id}/test")->assertSessionHas('warning');
        Http::assertNothingSent();
    }

    public function test_run_now_is_only_allowed_for_active_approved_automatic_sources(): void
    {
        Http::fake(['*/robots.txt' => Http::response('', 404), '*' => Http::response(file_get_contents(base_path('tests/Fixtures/jobweb-feed.xml')), 200)]);
        $source = $this->jobweb();

        $this->post("/sources/{$source->id}/run")->assertSessionHas('error');
        Http::assertNothingSent();

        app(SourceManager::class)->decideAccess($source, 'approved', 'ok');
        app(SourceManager::class)->activate($source->fresh());
        $this->post("/sources/{$source->id}/run")->assertSessionHas('status');

        $this->assertSame('manual', SourceRun::latest('id')->first()->trigger);
        $this->assertSame(2, RawJobPost::count());
    }

    public function test_a_new_source_always_starts_pending_and_inactive(): void
    {
        $response = $this->post('/sources', $this->validForm(['access_status' => 'approved', 'is_active' => '1']));

        $source = Source::where('name', 'Example Mining Careers')->first();
        $response->assertRedirect("/sources/{$source->id}");
        $this->assertSame('pending', $source->access_status);
        $this->assertFalse($source->is_active);
    }

    public function test_source_form_rejects_bad_input(): void
    {
        $this->post('/sources', $this->validForm(['website_url' => 'javascript:alert(1)']))->assertSessionHasErrors('website_url');
        $this->post('/sources', $this->validForm(['website_url' => 'https://jobwebzambia.com/']))->assertSessionHasErrors('website_url');
        $this->post('/sources', $this->validForm(['name' => '']))->assertSessionHasErrors('name');
        $this->post('/sources', $this->validForm(['collector' => 'not_a_collector']))->assertSessionHasErrors('collector');
        $this->post('/sources', $this->validForm(['min_interval_seconds' => 0]))->assertSessionHasErrors('min_interval_seconds');
        $this->post('/sources', $this->validForm(['collection_frequency' => 'hourly']))->assertSessionHasErrors('collection_frequency');
        $this->assertSame(0, Source::where('name', 'Example Mining Careers')->count());
    }

    public function test_editing_cannot_change_access_or_active(): void
    {
        $source = $this->jobweb();

        $this->put("/sources/{$source->id}", $this->validForm([
            'name' => 'JobWeb Zambia (renamed)', 'website_url' => $source->website_url, 'collection_method' => 'rss',
            'collector' => 'jobweb_feed', 'job_listing_url' => $source->job_listing_url,
            'access_status' => 'approved', 'is_active' => '1',
        ]))->assertRedirect("/sources/{$source->id}");

        $fresh = $source->fresh();
        $this->assertSame('JobWeb Zambia (renamed)', $fresh->name);
        $this->assertSame('pending', $fresh->access_status);
        $this->assertFalse($fresh->is_active);
    }

    public function test_delete_is_blocked_with_stored_ads_and_allowed_without(): void
    {
        $source = $this->jobweb();
        RawJobPost::create([
            'source_id' => $source->id, 'source_url' => 'https://x.test/1', 'content_hash' => str_repeat('a', 64),
            'fetched_at' => now(), 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->delete("/sources/{$source->id}")->assertSessionHas('error');
        $this->assertNotNull(Source::find($source->id));

        $un = Source::where('name', 'UN Jobs')->first();
        $this->delete("/sources/{$un->id}")->assertRedirect('/sources');
        $this->assertNull(Source::find($un->id));
    }

    public function test_every_write_needs_a_signed_in_admin(): void
    {
        $this->app['auth']->logout();
        $source = $this->jobweb();

        $this->post("/sources/{$source->id}/activate")->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['email' => 'intruder@example.com']))
            ->post("/sources/{$source->id}/access", ['status' => 'approved', 'note' => 'sneaky'])
            ->assertForbidden();
        $this->assertSame('pending', $this->jobweb()->access_status);
    }

    public function test_source_names_are_escaped_on_every_page(): void
    {
        $evil = Source::create([
            'name' => '<script>alert(1)</script>', 'website_url' => 'https://evil.test/', 'source_type' => 'company',
            'collection_method' => 'manual', 'collection_frequency' => 'daily', 'access_status' => 'pending', 'is_active' => false,
        ]);

        foreach (['/sources', "/sources/{$evil->id}", "/sources/{$evil->id}/edit", '/'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        }
    }
}
