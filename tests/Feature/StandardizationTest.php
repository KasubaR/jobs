<?php

namespace Tests\Feature;

use App\Jobs\ProcessRawJobPost;
use App\Models\Job;
use App\Models\RawJobPost;
use App\Models\Source;
use App\Standardization\DefaultTerms;
use App\Standardization\EmployerResolver;
use App\Standardization\EmploymentTypeParser;
use App\Standardization\ExperienceParser;
use App\Standardization\JobStandardizer;
use App\Standardization\LocationResolver;
use App\Standardization\MatchingKeys;
use App\Standardization\QualificationParser;
use App\Standardization\TermDictionary;
use App\Standardization\Text;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** Phase 6, steps 3 and 4: standardized values beside the originals, and the keys duplicates are matched on. */
class StandardizationTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
    }

    private function place(string $name, string $type = 'town'): object
    {
        return DB::table('locations')->where('name', $name)->where('type', $type)->first();
    }

    // ---- the dictionary ----

    public function test_the_starting_dictionary_and_places_are_installed_by_the_migration(): void
    {
        $this->assertSame(10, DB::table('locations')->where('type', 'province')->count());
        $this->assertNotNull($this->place('Kitwe'));
        $this->assertSame('Copperbelt', $this->place('Kitwe') ? DB::table('locations')->where('id', $this->place('Kitwe')->parent_id)->value('name') : null);
        $this->assertGreaterThan(50, DB::table('term_mappings')->where('origin', 'seed')->count());
    }

    public function test_installing_again_adds_nothing_twice_and_keeps_a_persons_correction(): void
    {
        DB::table('term_mappings')->where('kind', 'employment_type')->where('normalized', 'ft')->update(['standard_value' => 'part_time', 'origin' => 'person']);
        $before = DB::table('term_mappings')->count();

        DefaultTerms::install();
        DefaultTerms::install();

        $this->assertSame($before, DB::table('term_mappings')->count());
        $this->assertSame(10, DB::table('locations')->where('type', 'province')->count());
        $this->assertSame('part_time', DB::table('term_mappings')->where('kind', 'employment_type')->where('normalized', 'ft')->value('standard_value'));
    }

    // ---- text keys ----

    public function test_normalizing_text(): void
    {
        $this->assertSame('bsc', Text::normalize('B.Sc.'));
        $this->assertSame('bachelors in accounting', Text::normalize("Bachelor's in Accounting"));
        $this->assertSame('mba', Text::normalize('M.B.A'));
        $this->assertSame('north western', Text::normalize('North-Western'));
        $this->assertSame('zamtel', Text::employerKey('Zamtel (Z) Ltd'));
        $this->assertSame('zamtel', Text::employerKey('ZAMTEL'));
        $this->assertSame('zambia sugar', Text::employerKey('The Zambia Sugar Plc'));
        $this->assertSame('standard chartered bank zambia', Text::employerKey('Standard Chartered Bank Zambia Plc'));
        $this->assertNotSame(Text::employerKey('Zambia Sugar'), Text::employerKey('Zambia Breweries'));
    }

    // ---- qualification ----

    #[DataProvider('qualifications')]
    public function test_qualifications(string $text, string $label, ?string $field, string $level): void
    {
        $r = app(QualificationParser::class)->parse($text);

        $this->assertSame('ok', $r['status'], $text);
        $this->assertSame($label, $r['label'], $text);
        $this->assertSame($field, $r['field'], $text);
        $this->assertSame($level, $r['level'], $text);
    }

    public static function qualifications(): array
    {
        return [
            'from the brief: Bachelor of Science' => ['Bachelor of Science', "Bachelor's Degree", null, 'bachelor'],
            'from the brief: BSc Degree' => ['BSc Degree', "Bachelor's Degree", null, 'bachelor'],
            'dotted' => ['B.Sc.', "Bachelor's Degree", null, 'bachelor'],
            'from the brief: Bachelor\'s in Accounting' => ["Bachelor's in Accounting", "Bachelor's Degree in Accounting", 'Accounting', 'bachelor'],
            'type and subject' => ['Bachelor of Science in Mining Engineering', "Bachelor's Degree in Mining Engineering", 'Mining Engineering', 'bachelor'],
            'of names the subject' => ['Bachelor of Accounting', "Bachelor's Degree in Accounting", 'Accounting', 'bachelor'],
            'a plain degree is not called a bachelor' => ['Degree in Finance', 'Degree in Finance', 'Finance', 'degree'],
            'either subject' => ['Degree in Finance or Accounting', 'Degree in Finance or Accounting', 'Finance or Accounting', 'degree'],
            'trailing words are cut' => ['Diploma in Nursing from a recognized institution', 'Diploma in Nursing', 'Nursing', 'diploma'],
            'or related is cut' => ['Degree in Economics or related field', 'Degree in Economics', 'Economics', 'degree'],
            'any field is no field' => ['Degree in any field', 'Degree', null, 'degree'],
            'masters' => ["Master's degree in Public Health", "Master's Degree in Public Health", 'Public Health', 'masters'],
            'mba' => ['MBA', "Master's Degree", null, 'masters'],
            'doctorate' => ['PhD', 'Doctorate (PhD)', null, 'phd'],
            'school certificate is one level' => ['Grade 12 certificate', 'Grade 12', null, 'school'],
            'certificate' => ['Craft certificate in Welding', 'Certificate in Welding', 'Welding', 'certificate'],
            'professional alone' => ['ACCA', 'Professional Qualification', null, 'professional'],
            'professional beside a degree is not a second level' => ["Bachelor's degree and ACCA", "Bachelor's Degree", null, 'bachelor'],
        ];
    }

    public function test_qualification_text_that_is_not_known_or_names_two_levels_is_not_guessed(): void
    {
        $p = app(QualificationParser::class);

        $this->assertSame('unknown', $p->parse("Valid driver's licence")['status']);
        $this->assertSame('ambiguous', $p->parse('Diploma or Degree')['status']);
        $this->assertSame('ambiguous', $p->parse("Bachelor's or Master's degree")['status']);
        $this->assertSame('empty', $p->parse('  ')['status']);
        $this->assertSame('empty', $p->parse(null)['status']);
    }

    public function test_a_persons_mapping_for_the_exact_text_wins(): void
    {
        $this->assertSame('ambiguous', app(QualificationParser::class)->parse('Diploma or Degree')['status']);
        DB::table('term_mappings')->insert(['kind' => 'qualification', 'normalized' => 'diploma or degree', 'standard_value' => 'Diploma', 'meta' => json_encode(['level' => 'diploma', 'field' => null]), 'origin' => 'person', 'created_at' => now(), 'updated_at' => now()]);
        app(TermDictionary::class)->flush();

        $r = app(QualificationParser::class)->parse('Diploma or Degree');

        $this->assertSame('ok', $r['status']);
        $this->assertSame('Diploma', $r['label']);
    }

    // ---- experience ----

    #[DataProvider('experience')]
    public function test_experience(string $text, ?int $min, ?int $max, string $label): void
    {
        $r = app(ExperienceParser::class)->parse($text);

        $this->assertSame('ok', $r['status'], $text);
        $this->assertSame($min, $r['min'], $text);
        $this->assertSame($max, $r['max'], $text);
        $this->assertSame($label, $r['label'], $text);
    }

    public static function experience(): array
    {
        return [
            'from the brief' => ["3 years' experience", 36, null, '3 Years'],
            'written out' => ['three (3) years', 36, null, '3 Years'],
            'minimum' => ['minimum of 3 years relevant experience', 36, null, '3+ Years'],
            'at least' => ['at least 2 years', 24, null, '2+ Years'],
            'plus' => ['5+ years in a similar role', 60, null, '5+ Years'],
            'range' => ['2-3 years', 24, 36, '2–3 Years'],
            'range in words' => ['2 to 3 yrs', 24, 36, '2–3 Years'],
            'months to years' => ['36 months', 36, null, '3 Years'],
            'months' => ['6 months', 6, null, '6 Months'],
            'one year' => ['1 year', 12, null, '1 Year'],
            'part years' => ['1.5 years', 18, null, '18 Months'],
            'none' => ['No experience required', 0, null, 'No experience'],
            'fresh graduate' => ['Fresh graduate', 0, null, 'No experience'],
            'the same figure twice is one requirement' => ['5 years in mining, 5 years overall', 60, null, '5 Years'],
        ];
    }

    public function test_experience_with_two_different_figures_or_none_is_not_guessed(): void
    {
        $p = app(ExperienceParser::class);

        $this->assertSame('ambiguous', $p->parse('5 years in mining and 2 years in management')['status']);
        $this->assertSame('unknown', $p->parse('Relevant experience')['status']);
        $this->assertSame('empty', $p->parse('')['status']);
    }

    // ---- employment type ----

    public function test_employment_types(): void
    {
        $p = app(EmploymentTypeParser::class);

        $this->assertSame(['full_time', 'contract'], $p->parse('FULL_TIME, CONTRACT')['types']);
        $this->assertSame(['full_time', 'permanent'], $p->parse('Permanent / Full time')['types']); // always in the same order
        $this->assertSame(['permanent'], $p->parse('Permanent and pensionable')['types']);
        $this->assertSame(['internship'], $p->parse('Industrial attachment')['types']);
        $this->assertSame([], $p->parse('')['types']);

        $r = $p->parse('Full time, gig work');
        $this->assertSame(['full_time'], $r['types']); // what is known is still used
        $this->assertSame('gig work', $r['unknown'][0]['key']);
    }

    // ---- location ----

    #[DataProvider('places')]
    public function test_places(string $text, ?string $name, string $type, bool $multiple): void
    {
        $r = app(LocationResolver::class)->resolve($text);

        $this->assertNotNull($r['location_id'], $text);
        $place = DB::table('locations')->find($r['location_id']);
        $this->assertSame($name, $place->name, $text);
        $this->assertSame($type, $place->type, $text);
        $this->assertSame($multiple, $r['multiple'], $text);
        $this->assertSame([], $r['unknown'], $text);
    }

    public static function places(): array
    {
        return [
            'from the brief: Lusaka City' => ['Lusaka City', 'Lusaka', 'town', false],
            'with the country' => ['Lusaka, Zambia', 'Lusaka', 'town', false],
            'a town and its own province is one place' => ['Lusaka, Lusaka Province', 'Lusaka', 'town', false],
            'town and province' => ['Kitwe, Copperbelt Province', 'Kitwe', 'town', false],
            'a province alone' => ['Copperbelt', 'Copperbelt', 'province', false],
            'spelled apart' => ['Copper Belt', 'Copperbelt', 'province', false],
            'hyphen or space' => ['North Western Province', 'North-Western', 'province', false],
            'two towns' => ['Ndola; Kitwe', 'Ndola', 'town', true],
            'a town inside longer text' => ['Solwezi Mine Site', 'Solwezi', 'town', false],
            'upper case' => ['LIVINGSTONE', 'Livingstone', 'town', false],
        ];
    }

    public function test_place_words_that_are_not_places_are_not_guessed(): void
    {
        $r = app(LocationResolver::class);

        $head = $r->resolve('Head Office');
        $this->assertNull($head['location_id']);
        $this->assertSame('head office', $head['unknown'][0]['key']);

        $this->assertNull($r->resolve('Mine site in Solwezi or Kitwe')['location_id']); // two towns in one phrase: not picked for you
        foreach (['Zambia', 'Nationwide', 'Various', 'Republic of Zambia'] as $general) {
            $result = $r->resolve($general);
            $this->assertNull($result['location_id'], $general);
            $this->assertSame([], $result['unknown'], $general); // recognized, just not a particular place
        }

        $partly = $r->resolve('Kansanshi Mine, Solwezi');
        $this->assertSame('Solwezi', DB::table('locations')->find($partly['location_id'])->name);
        $this->assertSame('kansanshi mine', $partly['unknown'][0]['key']);
    }

    // ---- employer ----

    public function test_one_employer_written_two_ways_is_one_record_and_two_employers_stay_two(): void
    {
        $e = app(EmployerResolver::class);

        $a = $e->resolve('Zamtel (Z) Ltd');
        $b = $e->resolve('ZAMTEL');
        $c = $e->resolve('Zambia Sugar Plc');
        $d = $e->resolve('Zambia Breweries Plc');

        $this->assertSame($a, $b);
        $this->assertNotSame($c, $d);
        $this->assertNull($e->resolve(''));
        $this->assertNull($e->resolve(null));
        $this->assertSame('Zamtel (Z) Ltd', DB::table('employers')->find($a)->name); // the first spelling is kept for display
    }

    public function test_a_person_can_say_two_names_are_one_employer(): void
    {
        $main = app(EmployerResolver::class)->resolve('Konkola Copper Mines');
        DB::table('term_mappings')->insert(['kind' => 'employer', 'normalized' => 'kcm', 'meta' => json_encode(['employer_id' => $main]), 'origin' => 'person', 'created_at' => now(), 'updated_at' => now()]);
        app(TermDictionary::class)->flush();

        $this->assertSame($main, app(EmployerResolver::class)->resolve('KCM'));
    }

    // ---- matching keys (step 4) ----

    public function test_titles_are_matched_without_filler(): void
    {
        $same = [
            'Mining Engineer',
            'URGENT: Mining Engineer',
            'Mining Engineer – Vacancy (2 positions) Ref: HR/2026/014',
            '[URGENT] Mining Engineers',
            'We are hiring: Mining Engineer #4412',
        ];

        foreach ($same as $title) {
            $this->assertSame('mining engineer', MatchingKeys::title($title), $title);
        }

        $this->assertSame('budget analyst', MatchingKeys::title('Budget Analyst at Example Charity', 'Example Charity Ltd'));
        $this->assertSame('senior engineer', MatchingKeys::title('Sr. Engineer'));
        $this->assertSame(MatchingKeys::title('Snr Engineer'), MatchingKeys::title('Senior Engineer'));
        $this->assertSame('head finance', MatchingKeys::title('Head of Finance'));
    }

    public function test_titles_that_differ_are_not_made_equal(): void
    {
        $this->assertNotSame(MatchingKeys::title('Mining Engineer'), MatchingKeys::title('Mining Engineering Intern'));
        $this->assertNotSame(MatchingKeys::title('Accountant'), MatchingKeys::title('Accounts Assistant'));
        // an " at X" ending is only removed when X is the employer
        $this->assertSame('nurse night shift', MatchingKeys::title('Nurse at Night Shift', 'Example Charity'));
        // a title made only of filler is not emptied
        $this->assertSame('urgent vacancy', MatchingKeys::title('URGENT VACANCY'));
    }

    public function test_application_links_are_made_comparable(): void
    {
        $same = MatchingKeys::canonicalUrl('https://example.com/apply?id=5');

        $this->assertSame('https://example.com/apply?id=5', $same);
        $this->assertSame($same, MatchingKeys::canonicalUrl('HTTP://www.Example.com/apply/?utm_source=x&id=5&fbclid=abc#top'));
        $this->assertSame($same, MatchingKeys::canonicalUrl('https://example.com:443/apply?id=5&utm_medium=email'));
        $this->assertSame('https://example.com:8080/a', MatchingKeys::canonicalUrl('https://example.com:8080/a/'));
        $this->assertNotSame($same, MatchingKeys::canonicalUrl('https://example.com/apply?id=6')); // a real parameter is kept
        $this->assertNull(MatchingKeys::canonicalUrl('mailto:hr@example.com'));
        $this->assertNull(MatchingKeys::canonicalUrl('javascript:alert(1)'));
        $this->assertNull(MatchingKeys::canonicalUrl('not a url'));
        $this->assertNull(MatchingKeys::canonicalUrl(null));
    }

    private static function body(string $extra = '', ?string $swap = null): string
    {
        $text = 'The programme officer will plan and monitor community health projects across the district. '
            .'Duties include preparing quarterly reports, training volunteers, managing the project budget and visiting clinics. '
            .'The successful candidate must be able to travel, speak local languages and work closely with ministry staff. '
            .'Experience with donor reporting, data collection tools and community mobilisation is an advantage. '
            .'Strong communication, planning and organisational skills are essential for this post.';

        return $swap ? str_replace('clinics', $swap, $text).$extra : $text.$extra;
    }

    public function test_the_same_ad_with_different_boilerplate_has_the_same_fingerprint(): void
    {
        $siteA = "<p>Employer: Example Charity</p><p>Closing date: 30 November 2026</p><p>".self::body().'</p><p>How to apply: send your CV to hr@example.org or call +260 211 123456. Visit https://a.test/apply</p>';
        $siteB = '<p>Posted on 2 November 2026</p><p>'.self::body().'</p><p>Share this job on Facebook</p><p>Location: Kitwe</p>';

        [$fpA, $hashA] = MatchingKeys::fingerprint($siteA);
        [$fpB, $hashB] = MatchingKeys::fingerprint($siteB);

        $this->assertNotNull($fpA);
        $this->assertSame(16, strlen($fpA));
        $this->assertSame($fpA, $fpB);
        $this->assertSame($hashA, $hashB);
        $this->assertSame(0, MatchingKeys::distance($fpA, $fpB));
    }

    public function test_a_lightly_edited_ad_is_close_and_a_different_ad_is_far(): void
    {
        [$original] = MatchingKeys::fingerprint(self::body());
        [$edited, $editedHash] = MatchingKeys::fingerprint(self::body('', 'hospitals'));
        [$other] = MatchingKeys::fingerprint(str_repeat('The mine requires a certified heavy equipment operator to drive haul trucks, maintain machinery logs, follow blasting safety rules and report faults to the shift supervisor each morning. ', 3));

        $this->assertNotSame($editedHash, MatchingKeys::fingerprint(self::body())[1]); // not an exact copy
        $this->assertLessThanOrEqual(12, MatchingKeys::distance($original, $edited));  // one word changed
        $this->assertGreaterThanOrEqual(20, MatchingKeys::distance($original, $other)); // unrelated text sits near 32
    }

    public function test_a_text_too_short_to_say_anything_has_no_fingerprint(): void
    {
        $this->assertSame([null, null], MatchingKeys::fingerprint('Short ad. Apply now.'));
    }

    public function test_fingerprint_distance(): void
    {
        $this->assertSame(0, MatchingKeys::distance('00000000deadbeef', '00000000deadbeef'));
        $this->assertSame(64, MatchingKeys::distance('0000000000000000', 'ffffffffffffffff'));
        $this->assertSame(1, MatchingKeys::distance('0000000000000000', '0000000000000001'));
        $this->assertSame(1, MatchingKeys::distance('8000000000000000', '0000000000000000'));
    }

    // ---- the processor does all of it ----

    private function job(array $facts = [], array $ad = []): Job
    {
        static $n = 0;
        $n++;
        $source = Source::where('name', 'JobWeb Zambia')->first();

        $post = RawJobPost::create($ad + [
            'source_id' => $source->id, 'source_url' => "https://x.test/job-{$n}", 'raw_title' => 'Programme Officer',
            'raw_content' => '<p>'.self::body().'</p>', 'structured_data' => $facts ?: null, 'content_hash' => hash('sha256', "s{$n}"),
            'fetched_at' => now(), 'first_seen_at' => now()->subDay(), 'last_seen_at' => now(),
        ]);

        ProcessRawJobPost::dispatchSync($post->id);

        return Job::where('raw_job_post_id', $post->id)->firstOrFail();
    }

    public function test_a_job_is_standardized_and_keyed_and_the_originals_are_untouched(): void
    {
        $job = $this->job([
            'employer' => 'Example Charity Ltd', 'locations' => [['locality' => 'Lusaka City', 'region' => 'Lusaka Province']],
            'employment_type' => ['FULL_TIME', 'CONTRACT'], 'education' => "Bachelor's in Accounting", 'experience' => "3 years' experience",
            'date_posted' => '2026-11-01', 'valid_through' => '2026-12-15',
        ], ['raw_title' => 'URGENT: Programme Officer (2 positions)']);

        // originals, exactly as published
        $this->assertSame('Example Charity Ltd', $job->employer_text);
        $this->assertSame('Lusaka City, Lusaka Province', $job->location_text);
        $this->assertSame('FULL_TIME, CONTRACT', $job->employment_type);
        $this->assertSame("Bachelor's in Accounting", $job->qualification_text);
        $this->assertSame("3 years' experience", $job->experience_text);
        $this->assertSame('URGENT: Programme Officer (2 positions)', $job->title);

        // standardized, beside them
        $this->assertSame("Bachelor's Degree in Accounting", $job->qualification_label);
        $this->assertSame('bachelor', $job->qualification_level);
        $this->assertSame(4, $job->qualification_rank);
        $this->assertSame('Accounting', $job->qualification_field);
        $this->assertSame(36, $job->experience_min_months);
        $this->assertNull($job->experience_max_months);
        $this->assertSame('3 Years', $job->experience_label);
        $this->assertSame(['full_time', 'contract'], $job->employment_types);
        $this->assertSame('Lusaka', DB::table('locations')->find($job->location_id)->name);
        $this->assertFalse($job->location_multiple);
        $this->assertSame('Example Charity Ltd', DB::table('employers')->find($job->employer_id)->name);
        $this->assertSame(JobStandardizer::VERSION, (int) $job->standardization_version);

        // matching keys
        $this->assertSame('programme officer', $job->match_title);
        $this->assertSame('example charity', $job->match_employer);
        $this->assertSame('lusaka', $job->match_location);
        $this->assertSame('2026-11-01', $job->match_date->toDateString());
        $this->assertSame(16, strlen($job->content_fingerprint));
        $this->assertSame(64, strlen($job->content_hash_norm));
    }

    public function test_two_sites_carrying_the_same_ad_get_the_same_keys(): void
    {
        $a = $this->job(['employer' => 'Example Charity Ltd', 'valid_through' => '2026-12-15'], ['raw_title' => 'Programme Officer', 'raw_content' => '<p>Closing date: 15 December 2026</p><p>'.self::body().'</p>']);
        $b = $this->job(['employer' => 'EXAMPLE CHARITY', 'valid_through' => '2026-12-15'], ['raw_title' => 'URGENT: Programme Officer – Vacancy', 'raw_content' => '<p>'.self::body().'</p><p>Share this job</p>']);

        $this->assertSame($a->match_title, $b->match_title);
        $this->assertSame($a->match_employer, $b->match_employer);
        $this->assertSame($a->employer_id, $b->employer_id);
        $this->assertSame($a->content_hash_norm, $b->content_hash_norm);
        $this->assertSame($a->content_fingerprint, $b->content_fingerprint);
    }

    public function test_the_fingerprint_survives_the_raw_text_being_cleared(): void
    {
        $job = $this->job();
        $fingerprint = $job->content_fingerprint;
        $this->assertNotNull($fingerprint);

        $job->rawPost->update(['raw_content' => null, 'raw_purged_at' => now()]);
        app(JobStandardizer::class)->standardize($job->fresh());

        $this->assertSame($fingerprint, $job->fresh()->content_fingerprint);
    }

    public function test_an_ad_that_says_nothing_standardizable_leaves_everything_empty_not_guessed(): void
    {
        $job = $this->job([], ['raw_content' => 'Short.']);

        $this->assertNull($job->qualification_level);
        $this->assertNull($job->experience_label);
        $this->assertNull($job->employment_types);
        $this->assertNull($job->location_id);
        $this->assertNull($job->employer_id);
        $this->assertNull($job->match_employer);
        $this->assertNull($job->content_fingerprint); // too short to say anything
        $this->assertSame(0, DB::table('unrecognized_values')->where('job_id', $job->id)->count());
    }

    public function test_values_the_rules_cannot_standardize_are_listed_once_per_job_and_not_duplicated_on_reprocessing(): void
    {
        $job = $this->job(['education' => "Valid driver's licence", 'experience' => 'Relevant experience', 'employment_type' => 'Gig work', 'locations' => [['locality' => 'Head Office']]]);

        $this->assertSame(4, DB::table('unrecognized_values')->where('job_id', $job->id)->count());

        app(JobStandardizer::class)->standardize($job->fresh());
        app(JobStandardizer::class)->standardize($job->fresh());

        $this->assertSame(4, DB::table('unrecognized_values')->where('job_id', $job->id)->count());
        $this->assertSame(['employment_type', 'experience', 'location', 'qualification'], DB::table('unrecognized_values')->where('job_id', $job->id)->orderBy('kind')->pluck('kind')->all());
    }

    // ---- the screen ----

    public function test_the_screen_lists_waiting_values_grouped_with_job_counts(): void
    {
        $this->signIn();
        $this->job(['education' => "Valid driver's licence"]);
        $this->job(['education' => "VALID DRIVER'S LICENCE"]);
        $this->job(['education' => 'Diploma or Degree']);

        $this->get('/values')->assertOk()
            ->assertSee('Qualifications')->assertSee('VALID DRIVER&#039;S LICENCE', false)->assertSee('Names more than one')
            ->assertSee('Values to map');

        $row = DB::table('unrecognized_values')->selectRaw('normalized, count(distinct job_id) as jobs')->groupBy('normalized')->orderByDesc('jobs')->first();
        $this->assertSame('valid drivers licence', $row->normalized);
        $this->assertSame(2, (int) $row->jobs); // different capitals, one decision
    }

    public function test_one_decision_standardizes_every_job_waiting_on_it_and_is_remembered(): void
    {
        $this->signIn();
        $a = $this->job(['education' => "Valid driver's licence"]);
        $b = $this->job(['education' => "valid DRIVER'S licence"]);
        $other = $this->job(['education' => 'Diploma or Degree']);

        $this->post('/values', ['kind' => 'qualification', 'normalized' => 'valid drivers licence', 'action' => 'map', 'level' => 'certificate', 'field' => 'Driving'])
            ->assertRedirect('/values')->assertSessionHas('status', fn ($m) => str_contains($m, '2 jobs were updated'));

        $this->assertSame('Certificate in Driving', $a->fresh()->qualification_label);
        $this->assertSame('Certificate in Driving', $b->fresh()->qualification_label);
        $this->assertSame("Valid driver's licence", $a->fresh()->qualification_text); // the original stays
        $this->assertNull($other->fresh()->qualification_level);                    // another value is not touched
        $this->assertSame(0, DB::table('unrecognized_values')->where('normalized', 'valid drivers licence')->count());
        $this->assertSame('person', DB::table('term_mappings')->where('kind', 'qualification')->where('normalized', 'valid drivers licence')->value('origin'));

        // a job that arrives later with the same words is understood at once
        $later = $this->job(['education' => "Valid Driver's Licence"]);
        $this->assertSame('Certificate in Driving', $later->qualification_label);
    }

    public function test_mapping_an_experience_a_type_and_a_place(): void
    {
        $this->signIn();
        $job = $this->job(['experience' => 'Relevant experience', 'employment_type' => 'Gig work', 'locations' => [['locality' => 'Head Office']]]);
        $lusaka = $this->place('Lusaka');

        $this->post('/values', ['kind' => 'experience', 'normalized' => 'relevant experience', 'action' => 'map', 'min_years' => '1', 'max_years' => '2'])->assertSessionHasNoErrors();
        $this->post('/values', ['kind' => 'employment_type', 'normalized' => 'gig work', 'action' => 'map', 'type' => 'contract']);
        $this->post('/values', ['kind' => 'location', 'normalized' => 'head office', 'action' => 'map', 'location_id' => $lusaka->id]);

        $fresh = $job->fresh();
        $this->assertSame([12, 24, '1–2 Years'], [$fresh->experience_min_months, $fresh->experience_max_months, $fresh->experience_label]);
        $this->assertSame(['contract'], $fresh->employment_types);
        $this->assertSame($lusaka->id, $fresh->location_id);
        $this->assertSame(0, DB::table('unrecognized_values')->where('job_id', $job->id)->count());
    }

    public function test_not_applicable_clears_the_value_and_is_not_asked_again(): void
    {
        $this->signIn();
        $a = $this->job(['locations' => [['locality' => 'Head Office']]]);

        $this->post('/values', ['kind' => 'location', 'normalized' => 'head office', 'action' => 'ignore'])->assertRedirect('/values');

        $this->assertSame(0, DB::table('unrecognized_values')->where('job_id', $a->id)->count());
        $this->assertNull($a->fresh()->location_id);
        $this->assertSame('Head Office', $a->fresh()->location_text); // the original stays

        $b = $this->job(['locations' => [['locality' => 'Head Office']]]);
        $this->assertSame(0, DB::table('unrecognized_values')->where('job_id', $b->id)->count());
    }

    public function test_the_screen_refuses_bad_input_and_values_that_are_not_waiting(): void
    {
        $this->signIn();
        $this->job(['education' => "Valid driver's licence"]);

        $this->post('/values', ['kind' => 'qualification', 'normalized' => 'something nobody asked about', 'action' => 'map', 'level' => 'degree'])->assertStatus(422);
        $this->post('/values', ['kind' => 'qualification', 'normalized' => 'valid drivers licence', 'action' => 'map', 'level' => 'wizard'])->assertSessionHasErrors('level');
        $this->job(['experience' => 'Relevant experience']);
        $this->post('/values', ['kind' => 'experience', 'normalized' => 'relevant experience', 'action' => 'map', 'min_years' => '3', 'max_years' => '1'])->assertSessionHasErrors('max_years');
        $this->post('/values', ['kind' => 'bogus', 'normalized' => 'x', 'action' => 'map'])->assertSessionHasErrors('kind');
        $this->assertSame(0, DB::table('term_mappings')->where('origin', 'person')->count());
    }

    public function test_the_screen_needs_a_signed_in_administrator(): void
    {
        $this->get('/values')->assertRedirect('/login');
        $this->post('/values', ['kind' => 'qualification', 'normalized' => 'x', 'action' => 'ignore'])->assertRedirect('/login');
    }

    public function test_the_screen_says_so_when_nothing_is_waiting(): void
    {
        $this->signIn();

        $this->get('/values')->assertOk()->assertSee('Nothing is waiting');
    }

    // ---- redoing existing jobs ----

    public function test_the_command_standardizes_jobs_made_before_this_step(): void
    {
        $job = $this->job(['education' => 'BSc']);
        $job->update(['qualification_level' => null, 'qualification_label' => null, 'match_title' => null, 'standardization_version' => null]);

        $this->artisan('jobs:standardize')->expectsOutputToContain('Standardized 1 job.')->assertSuccessful();

        $fresh = $job->fresh();
        $this->assertSame("Bachelor's Degree", $fresh->qualification_label);
        $this->assertSame('programme officer', $fresh->match_title);

        $this->artisan('jobs:standardize')->expectsOutputToContain('Standardized 0 jobs.')->assertSuccessful(); // nothing older is left
        $this->artisan('jobs:standardize', ['--all' => true])->expectsOutputToContain('Standardized 1 job.')->assertSuccessful();
    }
}
