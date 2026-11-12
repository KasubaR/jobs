<?php

namespace Tests\Feature;

use App\Collectors\JobWebFeedCollector;
use App\Models\Job;
use App\Models\RawJobPost;
use App\Models\Source;
use App\Services\SourceManager;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ChecksDataset;
use Tests\Concerns\MakesJobs;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * Phase 6 acceptance: ads come in through the real collectors (an RSS feed and two file imports), and what comes out is a
 * clean, standardized dataset in which the same vacancy appears once. Saved files and faked HTTP only.
 *
 * The scenario, all synthetic: a Mining Engineer vacancy of Sample Mining Ltd on the company's own site, on a job board
 * (same text, employer written differently, title "... at ...") and, reworded, on a second board; plus look-alikes that
 * must stay separate, a tender notice, an expired ad and an ad too thin to trust.
 */
class Phase6EndToEndTest extends TestCase
{
    use ChecksDataset, MakesJobs, RefreshDatabase, SignsIn;

    private Source $company;

    private Source $boardA;

    private Source $boardB;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 12:00:00');
        config(['job_intel.collector_contact' => 'owner@example.com', 'job_intel.processing_enabled' => true]); // the queue is "sync" in tests
        $this->seed(SourceSeeder::class);

        $manual = Source::where('collection_method', 'manual')->whereNull('collector')->orderBy('id')->take(2)->get();
        $manual[0]->update(['collector' => 'manual_submission', 'source_type' => 'company', 'name' => 'Sample Mining careers', 'retain_raw' => true]);
        $manual[1]->update(['collector' => 'manual_submission', 'source_type' => 'job_board', 'name' => 'Board B', 'retain_raw' => true]);
        $this->company = $manual[0]->fresh();
        $this->boardB = $manual[1]->fresh();

        $this->boardA = Source::where('name', 'JobWeb Zambia')->first();
        $this->boardA->update(['source_type' => 'job_board', 'collector_config' => ['extraction' => ['title_separator' => ' at ']]]);
        $this->boardA = $this->boardA->fresh();

        foreach ([$this->company, $this->boardA, $this->boardB] as $source) {
            app(SourceManager::class)->decideAccess($source, 'approved', 'Test.');
            app(SourceManager::class)->activate($source->fresh());
        }

        $this->fakeFeed();
        $this->companyFile = $this->write('company.json', json_encode(['source' => $this->company->id, 'ads' => $this->companyAds()]));
        $this->boardBFile = $this->write('boardb.csv', $this->csv($this->boardBRows()));
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private string $companyFile;

    private string $boardBFile;

    private function write(string $name, string $content): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'p6-'.uniqid().'-'.$name;
        file_put_contents($path, $content);

        return $this->files[] = $path;
    }

    private function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        rewind($out);

        return stream_get_contents($out);
    }

    /** The job board's RSS feed, from a saved file. Any other address is an error: nothing reaches a real site. */
    private function fakeFeed(): void
    {
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::fake(fn (Request $r) => str_ends_with($r->url(), '/robots.txt')
            ? Http::response('', 404)
            : Http::response(file_get_contents(base_path('tests/Fixtures/phase6/board-feed.xml')), 200));
        Http::preventStrayRequests();
    }

    private function companyAds(): array
    {
        $safety = 'The safety officer inspects the shafts and workshops each shift, investigates incidents, trains new employees in emergency drills, '
            .'keeps the risk register up to date and reports to the mine manager every Friday. Applicants must hold a recognised safety certificate and know the mine health regulations well.';
        $tender = 'Sealed bids are invited from registered suppliers for the supply and delivery of blasting explosives to the Solwezi operation over twelve months. '
            .'Bid documents may be collected from the procurement office on payment of a refundable fee, and late bids will not be accepted under any circumstances.';
        $store = 'The store keeper receives, checks and issues spare parts, records every movement in the stock system, counts the store each month and reports shortages to the supervisor. '
            .'Applicants must be accurate, honest and comfortable working with simple stock software and heavy parts.';

        $common = ['employer' => 'Sample Mining Ltd', 'location' => 'Solwezi', 'date_posted' => '2026-11-02'];

        return [
            ['title' => 'Mining Engineer', 'url' => 'https://careers.samplemining.test/mining-engineer', 'external_id' => 'C1', 'content' => 'Closing date: 15 December 2026. '.self::mineBody(),
                'structured' => $common + ['valid_through' => '2026-12-15', 'employment_type' => 'FULL_TIME', 'education' => 'BSc Degree', 'experience' => "5 years' experience",
                    'application_url' => 'https://careers.samplemining.test/apply/mining-engineer']],
            ['title' => 'Mine Safety Officer', 'url' => 'https://careers.samplemining.test/safety-officer', 'external_id' => 'C2', 'content' => $safety,
                'structured' => $common + ['valid_through' => '2026-12-15']],
            ['title' => 'Tender for the supply of explosives', 'url' => 'https://careers.samplemining.test/tender-explosives', 'external_id' => 'C3', 'content' => $tender,
                'structured' => $common + ['valid_through' => '2026-12-01']],
            ['title' => 'Store Keeper', 'url' => 'https://careers.samplemining.test/store-keeper', 'external_id' => 'C4', 'content' => $store,
                'structured' => $common + ['valid_through' => '2026-10-01', 'date_posted' => '2026-09-01']],
        ];
    }

    private function boardBRows(): array
    {
        $when = ['valid_through' => '2026-12-15', 'date_posted' => '2026-11-03'];

        return [
            ['title', 'url', 'content', 'external_id', 'structured'],
            ['Mining Engineers (Vacancy)', 'https://board-b.test/j/501', self::mineBody('drainage'), 'B1', json_encode($when + ['employer' => 'Sample Mining', 'location' => 'Solwezi'])],
            ['Mining Engineer', 'https://board-b.test/j/502', self::mineBody(), 'B2', json_encode($when + ['employer' => 'Talent Hub Recruitment', 'location' => 'Solwezi'])],
            ['Mining Engineer', 'https://board-b.test/j/503', self::mineBody(), 'B3', json_encode($when + ['employer' => 'Another Mine Plc', 'location' => 'Kitwe'])],
        ];
    }

    /** One day's intake: the feed is collected and both files are imported. */
    private function intake(): void
    {
        app(JobWebFeedCollector::class)->collect($this->boardA->fresh());
        $this->artisan('collect:import', ['file' => $this->companyFile, '--source' => (string) $this->company->id])->assertSuccessful();
        $this->artisan('collect:import', ['file' => $this->boardBFile, '--source' => (string) $this->boardB->id])->assertSuccessful();
    }

    private function job(string $source, string $title): Job
    {
        $id = ['company' => $this->company->id, 'a' => $this->boardA->id, 'b' => $this->boardB->id][$source];

        return Job::whereIn('raw_job_post_id', RawJobPost::where('source_id', $id)->select('id'))
            ->where(fn ($q) => $q->where('title', $title)->orWhere('title', 'like', $title.'%'))->firstOrFail();
    }

    // ---- the whole chain ----

    public function test_the_same_vacancy_on_three_sites_becomes_one_main_job_in_a_clean_standardized_dataset(): void
    {
        $this->intake();

        // everything collected was processed, and nothing failed
        $this->assertSame(10, RawJobPost::count());
        $this->assertSame(10, RawJobPost::where('processing_status', 'processed')->count());
        $this->assertSame(10, Job::count());
        $this->assertDatasetIsSound();

        $main = $this->job('company', 'Mining Engineer');
        $boardCopy = $this->job('a', 'Mining Engineer');
        $reworded = $this->job('b', 'Mining Engineers');
        $agency = Job::where('employer_text', 'Talent Hub Recruitment')->firstOrFail();
        $kitwe = Job::where('employer_text', 'Another Mine Plc')->firstOrFail();

        // 6.1 duplicate detection: the company's own ad is the main one, the board's identical ad is its copy
        $this->assertSame('published', $main->status);
        $this->assertNull($main->duplicate_of_job_id);
        $this->assertSame('duplicate', $boardCopy->status);
        $this->assertSame($main->id, $boardCopy->duplicate_of_job_id);
        $this->assertSame('Mining Engineer', $boardCopy->title);                           // read "at Sample Mining Limited" off the title
        $this->assertSame('Sample Mining Limited', $boardCopy->employer_text);
        $this->assertSame($main->employer_id, $boardCopy->employer_id);                    // one employer record for three spellings
        $this->assertSame($main->match_title, $boardCopy->match_title);
        $this->assertSame($main->content_hash_norm, $boardCopy->content_hash_norm);
        $this->assertNotSame($boardCopy->source_url, $main->source_url);                   // the copy keeps its own link

        // the reworded ad is probably the same, but a person decides; the agency's ad and the other mine's ad are not merged
        $this->assertSame('published', $reworded->status);
        $this->assertSame($main->employer_id, $reworded->employer_id);
        $this->assertSame('published', $agency->status);
        $this->assertSame('published', $kitwe->status);
        $possible = DB::table('job_duplicate_candidates')->where('outcome', 'possible')->get();
        $this->assertCount(2, $possible);
        $waiting = $possible->flatMap(fn ($p) => [$p->job_a_id, $p->job_b_id])->unique();
        $this->assertTrue($waiting->contains($reworded->id) && $waiting->contains($agency->id));
        $this->assertFalse($waiting->contains($kitwe->id)); // a different employer in a different place is simply different

        // 6.2 standardization, beside the originals
        $this->assertSame("Bachelor's Degree", $main->qualification_label);
        $this->assertSame('BSc Degree', $main->qualification_text);
        $this->assertSame([60, null, '5 Years'], [$main->experience_min_months, $main->experience_max_months, $main->experience_label]);
        $this->assertSame("5 years' experience", $main->experience_text);
        $this->assertSame(['full_time'], $main->employment_types);
        $this->assertSame('Solwezi', DB::table('locations')->find($main->location_id)->name);
        $this->assertSame("Bachelor's Degree in Mining Engineering", $boardCopy->qualification_label);      // from "Bachelor of Science in Mining Engineering"
        $this->assertSame('Bachelor of Science in Mining Engineering', $boardCopy->qualification_text);
        $this->assertSame([60, '5 Years'], [$boardCopy->experience_min_months, $boardCopy->experience_label]); // from "five (5) years"
        $this->assertSame('Solwezi', DB::table('locations')->find($boardCopy->location_id)->name);          // from "Solwezi City"
        $this->assertSame('Solwezi City', $boardCopy->location_text);
        $this->assertSame(['full_time'], $boardCopy->employment_types);                                      // from "Full time"

        // 6.3 status: nothing is assumed open
        $this->assertSame('rejected', $this->job('company', 'Tender for the supply')->status);
        $this->assertSame('expired', $this->job('company', 'Store Keeper')->status);
        $thin = $this->job('a', 'URGENT: Accounts Clerk');
        $this->assertSame('new', $thin->status);
        $this->assertSame('needs_review', $thin->review_status);
        // an ad that states no qualification is not published by itself (the board's Cook ad names none)
        $cook = $this->job('a', 'Cook');
        $this->assertSame('new', $cook->status);
        $this->assertStringContainsString('no qualification', (string) $cook->status_reason);
        $this->assertSame(['duplicate' => 1, 'expired' => 1, 'new' => 2, 'published' => 5, 'rejected' => 1], $this->statusCounts()); // 10 ads: 1 copy, 1 tender, 1 expired, 1 too thin to trust, 1 with no qualification
    }

    /** @return array<string, int> */
    private function statusCounts(): array
    {
        $counts = Job::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->map(fn ($c) => (int) $c)->all();
        ksort($counts);

        return $counts;
    }

    public function test_a_second_intake_of_the_same_ads_changes_nothing(): void
    {
        $this->intake();
        $before = [
            'raw' => RawJobPost::count(), 'jobs' => Job::count(), 'pairs' => DB::table('job_duplicate_candidates')->count(),
            'history' => DB::table('job_status_changes')->count(), 'logs' => DB::table('extraction_logs')->count(), 'statuses' => $this->statusCounts(),
            'employers' => DB::table('employers')->count(),
        ];

        $this->intake();

        $this->assertSame($before, [
            'raw' => RawJobPost::count(), 'jobs' => Job::count(), 'pairs' => DB::table('job_duplicate_candidates')->count(),
            'history' => DB::table('job_status_changes')->count(), 'logs' => DB::table('extraction_logs')->count(), 'statuses' => $this->statusCounts(),
            'employers' => DB::table('employers')->count(),
        ]);
        $this->assertDatasetIsSound();
    }

    public function test_a_source_that_refuses_to_have_its_text_kept_leaves_no_text_and_no_fingerprint(): void
    {
        $this->boardB->update(['retain_raw' => false]); // like the sources whose terms forbid keeping their text
        $this->intake();

        $bAds = RawJobPost::where('source_id', $this->boardB->id)->get();
        $this->assertCount(3, $bAds);
        $this->assertTrue($bAds->every(fn ($p) => $p->raw_content === null));

        foreach (Job::whereIn('raw_job_post_id', $bAds->pluck('id'))->get() as $job) {
            $this->assertNull($job->content_fingerprint, 'a fingerprint was made from text that was never kept');
            $this->assertNull($job->content_hash_norm);
            $this->assertNotSame('duplicate', $job->status); // with no text to compare, an ad can be proposed but not merged
        }

        // the sources that do keep text are unaffected: the company ad and the board's copy still merge
        $this->assertSame('duplicate', $this->job('a', 'Mining Engineer')->status);
        $this->assertDatasetIsSound();
    }

    public function test_collection_and_processing_never_change_what_an_ad_said(): void
    {
        $this->intake();
        $after = $this->snapshotRawPosts();

        // the stored ads are exactly the collected ones: titles as the sources wrote them, and the feed's own text
        $this->assertContains('URGENT: Accounts Clerk', array_column($after, 'raw_title'));
        $this->assertContains('Mining Engineer at Sample Mining Limited', array_column($after, 'raw_title'));
        $this->assertContains('Mining Engineers (Vacancy)', array_column($after, 'raw_title'));

        // processing everything again (as after a rule change) leaves every stored ad byte for byte as it was
        $this->artisan('jobs:standardize', ['--all' => true])->assertSuccessful();
        DB::table('jobs')->update(['extraction_version' => 0]);
        $this->artisan('processing:dispatch', ['--outdated' => true])->assertSuccessful();
        $this->artisan('jobs:sweep-status')->assertSuccessful();

        $this->assertSame($after, $this->snapshotRawPosts());
        $this->assertSame(10, Job::count());
        $this->assertDatasetIsSound();
    }

    public function test_when_the_ad_text_is_cleared_after_90_days_the_dataset_keeps_what_it_learned(): void
    {
        $this->intake();
        $main = $this->job('company', 'Mining Engineer');
        $fingerprint = $main->content_fingerprint;
        $copyId = $this->job('a', 'Mining Engineer')->id;
        $this->assertNotNull($fingerprint);

        Carbon::setTestNow('2027-02-10 04:00:00'); // 94 days later: the raw text is cleared, and the closing dates are long past
        $this->artisan('raw:purge')->assertSuccessful();
        $this->assertSame(0, RawJobPost::whereNotNull('raw_content')->count());

        $this->artisan('jobs:standardize', ['--all' => true])->assertSuccessful(); // rebuilding the keys must not lose the fingerprint
        $this->artisan('jobs:sweep-status')->assertSuccessful();

        $main = $main->fresh();
        $this->assertSame($fingerprint, $main->content_fingerprint);
        $this->assertSame("Bachelor's Degree", $main->qualification_label);
        $this->assertSame('expired', $main->status);                       // the closing date has long passed
        $this->assertSame('duplicate', Job::find($copyId)->status);          // a copy stays a copy
        $this->assertSame('rejected', $this->job('company', 'Tender for the supply')->status);
        $this->assertDatasetIsSound();
    }

    public function test_people_can_settle_what_the_rules_left_open_and_the_dataset_stays_sound(): void
    {
        $this->signIn();
        $this->intake();
        $this->assertSame(2, DB::table('job_duplicate_candidates')->where('outcome', 'possible')->count());

        // the screens list what is waiting
        $this->get('/duplicates')->assertOk()->assertSee('Score')->assertSee('Sample Mining careers');
        $this->get('/jobs?review=needs_review')->assertOk()->assertSee('Accounts Clerk');
        $this->get('/')->assertOk()->assertSee('Jobs by status')->assertSee('possible duplicates');

        // one decision for each: the reworded ad is the same vacancy, the agency's ad is a different listing
        $reworded = $this->job('b', 'Mining Engineers');
        $agency = Job::where('employer_text', 'Talent Hub Recruitment')->firstOrFail();
        $pairs = DB::table('job_duplicate_candidates')->where('outcome', 'possible')->get();
        $pairFor = fn (Job $j) => $pairs->first(fn ($p) => in_array($j->id, [$p->job_a_id, $p->job_b_id], true));

        $main = $this->job('company', 'Mining Engineer');
        $this->post("/duplicates/{$pairFor($reworded)->id}/confirm", ['main' => $main->id])->assertSessionHasNoErrors();
        $this->post("/duplicates/{$pairFor($agency)->id}/reject")->assertSessionHasNoErrors();

        $this->assertSame($main->id, $reworded->fresh()->duplicate_of_job_id);
        $this->assertSame('published', $agency->fresh()->status);
        $this->assertSame(0, DB::table('job_duplicate_candidates')->where('outcome', 'possible')->count());

        // and the thin ad is looked at and approved
        $thin = $this->job('a', 'URGENT: Accounts Clerk');
        $this->post("/jobs/{$thin->id}/approve")->assertSessionHas('status');
        $this->assertSame('published', $thin->fresh()->status);

        $this->assertDatasetIsSound();

        // a later intake does not undo any of it
        $this->intake();
        $this->assertSame($main->id, $reworded->fresh()->duplicate_of_job_id);
        $this->assertSame(0, DB::table('job_duplicate_candidates')->where('outcome', 'possible')->count());
        $this->assertSame('published', $thin->fresh()->status);
        $this->assertDatasetIsSound();
    }
}
