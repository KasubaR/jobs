<?php

namespace Tests\Feature;

use App\Collectors\ManualSubmissionCollector;
use App\Models\RawJobPost;
use App\Models\Source;
use App\Models\SourceRun;
use App\Services\SourceManager;
use Database\Seeders\SourceSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class ManualSubmissionTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->seed(SourceSeeder::class);
    }

    private function tsc(bool $ready = true): Source
    {
        $source = Source::where('name', 'Teaching Service Commission')->first();

        if ($ready) {
            app(SourceManager::class)->decideAccess($source, 'approved', 'Public government notice.');
            app(SourceManager::class)->activate($source->fresh());
        }

        return $source->fresh();
    }

    private function ad(array $overrides = []): array
    {
        return $overrides + ['title' => 'Primary School Teacher at Teaching Service Commission', 'url' => '', 'content' => 'Apply by 28 April 2026. Send CV to hr@tsc.example.'];
    }

    public function test_the_teaching_service_commission_is_a_manual_source(): void
    {
        $source = $this->tsc(false);

        $this->assertSame('manual', $source->collection_method);
        $this->assertSame('manual_submission', $source->collector);
        $this->assertSame([], app(SourceManager::class)->activationProblems(
            tap($source)->forceFill(['access_status' => 'approved'])
        ));
    }

    public function test_a_pasted_ad_is_stored_through_the_normal_rules_without_any_request(): void
    {
        $source = $this->tsc();

        $run = app(SourceManager::class)->submitAd($source, $this->ad());

        $this->assertSame('success', $run->status);
        $this->assertSame('manual', $run->trigger);
        $this->assertSame(1, $run->items_new);

        $post = RawJobPost::first();
        $this->assertSame($source->id, $post->source_id);
        $this->assertStringNotContainsString('hr@tsc.example', $post->raw_content);
        $this->assertStringContainsString('[email removed]', $post->raw_content);
        $this->assertStringStartsWith('manual:', $post->source_url);
        $this->assertNotNull($source->fresh()->last_collection_at);
        Http::assertNothingSent();
    }

    public function test_pasting_the_same_ad_twice_stores_it_once_and_an_edit_is_a_new_version(): void
    {
        $source = $this->tsc();
        $manager = app(SourceManager::class);

        $manager->submitAd($source, $this->ad());
        $again = $manager->submitAd($source, $this->ad());
        $this->assertSame(0, $again->items_new);
        $this->assertSame(1, RawJobPost::count());

        $edited = $manager->submitAd($source, $this->ad(['content' => 'Apply by 5 May 2026.']));
        $this->assertSame(1, $edited->items_updated);
        $this->assertSame(2, RawJobPost::count());
    }

    public function test_a_source_that_refuses_retention_keeps_no_raw_text(): void
    {
        $source = $this->tsc();
        $source->update(['retain_raw' => false]);

        app(SourceManager::class)->submitAd($source->fresh(), $this->ad());

        $this->assertNull(RawJobPost::first()->raw_content);
    }

    public function test_a_link_is_kept_when_given_and_must_be_http(): void
    {
        $source = $this->tsc();
        $manager = app(SourceManager::class);

        $manager->submitAd($source, $this->ad(['url' => 'https://tcz.example/advert.pdf']));
        $this->assertSame('https://tcz.example/advert.pdf', RawJobPost::first()->source_url);

        $this->expectException(DomainException::class);
        $manager->submitAd($source, $this->ad(['url' => 'javascript:alert(1)']));
    }

    public function test_title_and_text_are_required_and_the_text_is_size_limited(): void
    {
        $source = $this->tsc();

        foreach ([['title' => ' '], ['content' => ''], ['content' => str_repeat('a', 100_001)]] as $bad) {
            try {
                app(SourceManager::class)->submitAd($source, $this->ad($bad));
                $this->fail('Expected a DomainException.');
            } catch (DomainException) {
                $this->assertSame(0, RawJobPost::count());
            }
        }
    }

    public function test_unapproved_or_inactive_manual_sources_refuse_ads(): void
    {
        $source = $this->tsc(false); // pending

        $this->expectException(RuntimeException::class);
        try {
            app(SourceManager::class)->submitAd($source, $this->ad());
        } finally {
            $this->assertSame(0, RawJobPost::count());
        }
    }

    public function test_an_automatic_source_cannot_take_pasted_ads(): void
    {
        $jobweb = Source::where('name', 'JobWeb Zambia')->first();

        $this->expectException(DomainException::class);
        app(SourceManager::class)->submitAd($jobweb, $this->ad());
    }

    public function test_the_scheduler_never_collects_manual_sources_and_naming_one_explains_why(): void
    {
        $this->tsc();

        $this->artisan('collect:run')->expectsOutputToContain('No approved, active sources are due')->assertSuccessful();
        $this->artisan('collect:run', ['source' => 'Teaching Service Commission'])->expectsOutputToContain('manual source')->assertFailed();
        $this->assertSame(0, SourceRun::count());
    }

    public function test_testing_access_on_a_manual_source_makes_no_request(): void
    {
        $source = $this->tsc();

        $result = app(ManualSubmissionCollector::class)->check($source);

        $this->assertTrue($result->ok);
        Http::assertNothingSent();
    }

    public function test_the_add_ad_button_appears_only_for_ready_manual_sources(): void
    {
        $this->signIn();

        $notReady = $this->tsc(false);
        $this->get("/sources/{$notReady->id}")->assertOk()->assertDontSee('Add ad')->assertDontSee('Run now');

        $ready = $this->tsc();
        $this->get("/sources/{$ready->id}")->assertOk()->assertSee('Add ad')->assertDontSee('Run now');

        $jobweb = Source::where('name', 'JobWeb Zambia')->first();
        $this->get("/sources/{$jobweb->id}")->assertOk()->assertDontSee('Add ad');
    }

    public function test_adding_an_ad_through_the_page_stores_it(): void
    {
        $this->signIn();
        $ready = $this->tsc();

        $this->post("/sources/{$ready->id}/ads", ['title' => 'Teacher at TSC', 'url' => '', 'content' => 'Apply now. hr@tsc.example'])
            ->assertSessionHas('status');

        $post = RawJobPost::where('source_id', $ready->id)->first();
        $this->assertSame('Teacher at TSC', $post->raw_title);
        $this->assertStringNotContainsString('hr@tsc.example', $post->raw_content);
        Http::assertNothingSent();
    }

    public function test_adding_an_ad_is_validated_and_refused_for_unready_or_automatic_sources(): void
    {
        $this->signIn();
        $ready = $this->tsc();

        $this->post("/sources/{$ready->id}/ads", ['title' => '', 'content' => ''])->assertSessionHasErrors(['title', 'content']);
        $this->post("/sources/{$ready->id}/ads", ['title' => 'x', 'content' => 'y', 'url' => 'javascript:alert(1)'])->assertSessionHasErrors('url');

        $pending = Source::where('name', 'Teaching Service Commission')->first();
        app(SourceManager::class)->deactivate($pending);
        $this->post("/sources/{$pending->id}/ads", ['title' => 'x', 'content' => 'y'])->assertSessionHas('error');

        $jobweb = Source::where('name', 'JobWeb Zambia')->first();
        $this->post("/sources/{$jobweb->id}/ads", ['title' => 'x', 'content' => 'y'])->assertSessionHas('error');
        $this->assertSame(0, RawJobPost::count());
    }
}
