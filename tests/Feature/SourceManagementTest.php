<?php

namespace Tests\Feature;

use App\Filament\Resources\Sources\Pages\CreateSource;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Filament\Resources\Sources\RelationManagers\AccessLogsRelationManager;
use App\Filament\Resources\Sources\RelationManagers\ErrorsRelationManager;
use App\Filament\Resources\Sources\RelationManagers\RunsRelationManager;
use App\Models\RawJobPost;
use App\Models\Source;
use App\Models\SourceAccessLog;
use App\Models\SourceRun;
use App\Models\User;
use App\Services\SourceManager;
use Database\Seeders\SourceSeeder;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SourceManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['job_intel.admin_email' => 'me@example.com', 'job_intel.collector_contact' => 'owner@example.com']);
        $this->admin = User::factory()->create(['email' => 'me@example.com']);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seed(SourceSeeder::class);
    }

    private function jobweb(): Source
    {
        return Source::where('name', 'JobWeb Zambia')->first();
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
        $source = Source::where('name', 'Go Zambia Jobs')->first();
        app(SourceManager::class)->decideAccess($source, 'approved', 'Owner checked the terms.');

        $problems = app(SourceManager::class)->activationProblems($source->fresh());

        $this->assertStringContainsString('No collector is assigned', implode(' ', $problems));
        $this->expectException(DomainException::class);
        app(SourceManager::class)->activate($source->fresh());
    }

    public function test_deciding_access_needs_a_note_and_a_valid_status(): void
    {
        $manager = app(SourceManager::class);

        foreach ([['approved', '  '], ['maybe', 'note']] as [$status, $note]) {
            try {
                $manager->decideAccess($this->jobweb(), $status, $note);
                $this->fail('Expected a DomainException.');
            } catch (DomainException) {
                $this->assertSame('pending', $this->jobweb()->access_status);
            }
        }
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

    public function test_list_shows_sources_and_filters_to_failing(): void
    {
        $this->jobweb()->forceFill(['consecutive_failures' => 2])->save();

        Livewire::test(ListSources::class)
            ->assertCanSeeTableRecords(Source::all())
            ->filterTable('failing')
            ->assertCanSeeTableRecords([$this->jobweb()])
            ->assertCanNotSeeTableRecords(Source::where('name', 'UN Jobs')->get());
    }

    public function test_decide_access_action_records_the_decision(): void
    {
        Livewire::test(ListSources::class)
            ->callTableAction('decideAccess', $this->jobweb(), data: ['status' => 'approved', 'note' => 'Checked terms today.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('approved', $this->jobweb()->access_status);
        $this->assertSame('Checked terms today.', $this->jobweb()->access_decision_note);
    }

    public function test_decide_access_action_requires_a_note(): void
    {
        Livewire::test(ListSources::class)
            ->callTableAction('decideAccess', $this->jobweb(), data: ['status' => 'approved', 'note' => ''])
            ->assertHasTableActionErrors(['note' => 'required']);

        $this->assertSame('pending', $this->jobweb()->access_status);
    }

    public function test_activate_action_is_refused_for_an_unapproved_source(): void
    {
        Livewire::test(ListSources::class)->callTableAction('activate', $this->jobweb());

        $this->assertFalse($this->jobweb()->is_active);
    }

    public function test_activate_then_deactivate_actions_work_for_an_approved_source(): void
    {
        app(SourceManager::class)->decideAccess($this->jobweb(), 'approved', 'ok');

        Livewire::test(ListSources::class)
            ->callTableAction('activate', $this->jobweb())
            ->assertTableActionHidden('activate', $this->jobweb()->fresh());
        $this->assertTrue($this->jobweb()->is_active);

        Livewire::test(ListSources::class)->callTableAction('deactivate', $this->jobweb());
        $this->assertFalse($this->jobweb()->is_active);
    }

    public function test_test_access_makes_requests_but_stores_nothing(): void
    {
        Http::fake(['*/robots.txt' => Http::response('', 404), '*' => Http::response(file_get_contents(base_path('tests/Fixtures/jobweb-feed.xml')), 200)]);

        Livewire::test(ListSources::class)->callTableAction('testAccess', $this->jobweb());

        $this->assertSame(0, RawJobPost::count());
        $this->assertSame(0, SourceRun::count());
    }

    public function test_test_access_is_not_offered_for_restricted_sources(): void
    {
        Livewire::test(ListSources::class)
            ->assertTableActionHidden('testAccess', Source::where('name', 'UN Jobs')->first())
            ->assertTableActionVisible('testAccess', $this->jobweb());
    }

    public function test_run_now_is_only_offered_for_active_approved_sources_and_records_a_manual_run(): void
    {
        Http::fake(['*/robots.txt' => Http::response('', 404), '*' => Http::response(file_get_contents(base_path('tests/Fixtures/jobweb-feed.xml')), 200)]);

        Livewire::test(ListSources::class)->assertTableActionHidden('runNow', $this->jobweb());

        app(SourceManager::class)->decideAccess($this->jobweb(), 'approved', 'ok');
        app(SourceManager::class)->activate($this->jobweb());

        Livewire::test(ListSources::class)->callTableAction('runNow', $this->jobweb());

        $run = SourceRun::latest('id')->first();
        $this->assertSame('manual', $run->trigger);
        $this->assertSame(2, RawJobPost::count());
    }

    public function test_a_new_source_always_starts_pending_and_inactive(): void
    {
        Livewire::test(CreateSource::class)
            ->fillForm([
                'name' => 'Example Mining Careers',
                'source_type' => 'company',
                'website_url' => 'https://careers.example.com',
                'collection_method' => 'manual',
                'collection_frequency' => 'weekly',
                'min_interval_seconds' => 2,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $source = Source::where('name', 'Example Mining Careers')->first();
        $this->assertSame('pending', $source->access_status);
        $this->assertFalse($source->is_active);
    }

    public function test_source_form_rejects_a_non_http_url_and_a_duplicate_site(): void
    {
        Livewire::test(CreateSource::class)
            ->fillForm([
                'name' => 'Bad', 'source_type' => 'company', 'collection_method' => 'manual',
                'collection_frequency' => 'daily', 'min_interval_seconds' => 1,
                'website_url' => 'javascript:alert(1)',
            ])
            ->call('create')
            ->assertHasFormErrors(['website_url']);

        Livewire::test(CreateSource::class)
            ->fillForm([
                'name' => 'Dupe', 'source_type' => 'job_board', 'collection_method' => 'rss',
                'collection_frequency' => 'daily', 'min_interval_seconds' => 1,
                'website_url' => 'https://jobwebzambia.com/',
            ])
            ->call('create')
            ->assertHasFormErrors(['website_url']);
    }

    public function test_editing_cannot_change_access_or_active(): void
    {
        $source = $this->jobweb();

        Livewire::test(EditSource::class, ['record' => $source->getRouteKey()])
            ->fillForm(['name' => 'JobWeb Zambia (renamed)', 'access_status' => 'approved', 'is_active' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $source->fresh();
        $this->assertSame("JobWeb Zambia (renamed)", $fresh->name);
        $this->assertSame('pending', $fresh->access_status);
        $this->assertFalse($fresh->is_active);
    }

    public function test_history_and_error_tabs_show_the_right_runs(): void
    {
        $source = $this->jobweb();
        $ok = SourceRun::create(['source_id' => $source->id, 'started_at' => now(), 'finished_at' => now(), 'status' => 'success']);
        $bad = SourceRun::create(['source_id' => $source->id, 'started_at' => now(), 'finished_at' => now(), 'status' => 'failed', 'error_message' => 'Site returned HTTP 503']);

        $props = ['ownerRecord' => $source, 'pageClass' => EditSource::class];

        Livewire::test(RunsRelationManager::class, $props)->assertCanSeeTableRecords([$ok, $bad]);
        Livewire::test(ErrorsRelationManager::class, $props)
            ->assertCanSeeTableRecords([$bad])
            ->assertCanNotSeeTableRecords([$ok])
            ->assertSee('Site returned HTTP 503');

        app(SourceManager::class)->decideAccess($source, 'approved', 'ok');
        Livewire::test(AccessLogsRelationManager::class, $props)->assertCountTableRecords(1);
    }

    public function test_a_non_admin_cannot_open_the_source_screens(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'intruder@example.com']));

        $this->get('/sources')->assertForbidden();
    }
}
