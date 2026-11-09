<?php

namespace Tests\Feature;

use App\Standardization\QualificationParser;
use App\Taxonomy\DefaultTaxonomy;
use App\Taxonomy\TaxonomyIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Phase 7, step 1: the taxonomy and dictionaries the extraction engine maps wording onto. */
class TaxonomyTest extends TestCase
{
    use RefreshDatabase;

    private function t(): TaxonomyIndex
    {
        return app(TaxonomyIndex::class);
    }

    /** @return list<string> */
    private function names(string $kind, string $text): array
    {
        return array_map(fn ($e) => $e->label, $this->t()->mentions($kind, $text));
    }

    // ---- what is installed ----

    public function test_the_migration_installs_the_taxonomy(): void
    {
        $this->assertSame(21, DB::table('industries')->count());                      // ISIC Rev. 4 sections A to U
        $this->assertSame(10, DB::table('occupations')->where('level', 'major')->count());
        $this->assertSame(43, DB::table('occupations')->where('level', 'submajor')->count());
        $this->assertGreaterThan(150, DB::table('occupations')->where('level', 'unit')->count());
        $this->assertSame(7, DB::table('seniority_levels')->count());
        $this->assertGreaterThan(200, DB::table('skills')->count());
        $this->assertGreaterThan(25, DB::table('academic_awards')->count());
        $this->assertGreaterThan(25, DB::table('professional_qualifications')->count());
        $this->assertGreaterThan(10, DB::table('professional_registrations')->count());
        $this->assertSame(15, DB::table('fields_of_study')->whereNull('parent_id')->count());
        $this->assertGreaterThan(80, DB::table('fields_of_study')->whereNotNull('parent_id')->count());
        $this->assertSame(['language', 'software', 'soft', 'technical'], DB::table('skills')->distinct()->orderBy('type')->pluck('type')->sort()->values()->all() === ['language', 'soft', 'software', 'technical'] ? ['language', 'software', 'soft', 'technical'] : []);
        $this->assertSame(0, DB::table('skills')->where('origin', '!=', 'seed')->count());
    }

    public function test_installing_again_adds_an_alias_the_data_file_gained_to_a_row_still_marked_seed(): void
    {
        $aliases = fn () => json_decode(DB::table('occupations')->where('code', '3313')->value('aliases'), true);
        DB::table('occupations')->where('code', '3313')->update(['aliases' => json_encode(['accounts assistant', 'my own title'])]);   // an older install, plus something a person added

        $added = DefaultTaxonomy::install();

        $this->assertContains('finance officer', $aliases());
        $this->assertContains('my own title', $aliases(), 'nothing is removed');
        $this->assertGreaterThan(0, $added['occupations (aliases)']);
        $this->assertSame(0, DefaultTaxonomy::install()['occupations (aliases)'] ?? 0, 'and a second install adds nothing more');

        DB::table('occupations')->where('code', '3313')->update(['aliases' => json_encode(['accounts assistant']), 'origin' => 'person']);
        DefaultTaxonomy::install();
        $this->assertSame(['accounts assistant'], $aliases(), 'a row a person has taken over is not touched');
    }

    public function test_titles_seen_in_real_ads_find_their_occupation(): void
    {
        foreach ([['Finance Officer', '3313'], ['Application Support Analyst', '3512'], ['Monitoring and Evaluation Expert', '2422'], ['Budgeting Analyst', '2413']] as [$title, $code]) {
            $this->assertSame($code, $this->t()->occupation($title)?->code, $title);
        }
    }

    public function test_installing_again_adds_nothing_and_never_changes_an_existing_row(): void
    {
        DB::table('skills')->where('name', 'Microsoft Excel')->update(['aliases' => json_encode(['MS Excel', 'my own wording']), 'origin' => 'person']);
        DB::table('professional_qualifications')->where('key', 'acca')->update(['name' => 'ACCA (edited)', 'origin' => 'person']);
        DB::table('skills')->insert(['name' => 'Beekeeping at scale', 'type' => 'technical', 'category' => 'Agriculture', 'aliases' => null, 'origin' => 'person', 'created_at' => now(), 'updated_at' => now()]);
        $before = [DB::table('skills')->count(), DB::table('occupations')->count(), DB::table('fields_of_study')->count(), DB::table('academic_awards')->count()];

        $added = DefaultTaxonomy::install();
        DefaultTaxonomy::install();

        $this->assertSame($before, [DB::table('skills')->count(), DB::table('occupations')->count(), DB::table('fields_of_study')->count(), DB::table('academic_awards')->count()]);
        $this->assertSame(0, array_sum($added));
        $this->assertSame(['MS Excel', 'my own wording'], json_decode(DB::table('skills')->where('name', 'Microsoft Excel')->value('aliases'), true)); // a person's edit is kept
        $this->assertSame('ACCA (edited)', DB::table('professional_qualifications')->where('key', 'acca')->value('name'));
        $this->assertSame(1, DB::table('skills')->where('name', 'Beekeeping at scale')->count());                                                        // so is a person's addition
    }

    public function test_a_row_removed_by_mistake_comes_back_but_others_are_untouched(): void
    {
        DB::table('professional_registrations')->where('key', 'hpcz-registration')->delete();

        $added = DefaultTaxonomy::install();

        $this->assertSame(1, $added['professional_registrations']);
        $this->assertSame(1, DB::table('professional_registrations')->where('key', 'hpcz-registration')->count());
    }

    public function test_the_artisan_command_reports_and_is_safe_to_repeat(): void
    {
        $this->artisan('taxonomy:install')->expectsOutputToContain('The taxonomy is up to date.')->assertSuccessful();
        $this->artisan('taxonomy:install')->assertSuccessful();

        $this->assertSame(21, DB::table('industries')->count());
    }

    // ---- the data is sound ----

    public function test_no_phrase_names_two_different_entries(): void
    {
        foreach (array_keys(TaxonomyIndex::KINDS) as $kind) {
            $this->assertSame([], $this->t()->conflicts($kind), "Phrases in '{$kind}' that name more than one entry: ".json_encode($this->t()->conflicts($kind)));
        }
    }

    public function test_the_occupation_hierarchy_is_complete(): void
    {
        $codes = DB::table('occupations')->pluck('level', 'code');

        $this->assertSame(DB::table('occupations')->count(), DB::table('occupations')->distinct()->count('code')); // "01" and "1" are different codes
        foreach (DB::table('occupations')->where('level', 'unit')->get() as $unit) {
            $this->assertSame(4, strlen($unit->code), $unit->code);
            $this->assertSame('submajor', $codes[$unit->parent_code] ?? null, "unit {$unit->code} has no sub-major group {$unit->parent_code}");
        }
        foreach (DB::table('occupations')->where('level', 'submajor')->get() as $sub) {
            $this->assertSame('major', $codes[$sub->parent_code] ?? null, "sub-major {$sub->code} has no major group");
        }
    }

    public function test_every_reference_between_taxonomy_tables_points_somewhere(): void
    {
        $fields = DB::table('fields_of_study')->pluck('key')->all();

        foreach (DB::table('academic_awards')->whereNotNull('field_key')->get() as $award) {
            $this->assertContains($award->field_key, $fields, "award {$award->key} implies an unknown field {$award->field_key}");
        }
        foreach (DB::table('professional_qualifications')->whereNotNull('field_key')->get() as $q) {
            $this->assertContains($q->field_key, $fields, "{$q->key} points at an unknown field {$q->field_key}");
        }
        foreach (DB::table('academic_awards')->get() as $award) {
            $this->assertContains($award->level, ['school', 'certificate', 'diploma', 'bachelor', 'postgraduate', 'masters', 'phd'], $award->key);
        }
        foreach (DB::table('professional_registrations')->get() as $r) {
            $this->assertContains($r->kind, ['membership', 'registration', 'licence'], $r->key);
        }
        foreach (DB::table('skills')->get() as $skill) {
            $this->assertNotNull($skill->category, "skill {$skill->name} has no category");
        }
    }

    // ---- your brief, 7.2: one meaning, many wordings ----

    /** @return array{level:?string, award:?object, fields:list<string>, broad:list<string>} */
    private function classify(string $text): array
    {
        $parsed = app(QualificationParser::class)->parse($text);
        $award = $this->t()->mentions('award', $text)[0] ?? null;
        $fields = $this->t()->mentions('field', $text);
        $broad = array_values(array_unique(array_map(fn ($f) => ($f->parent ?? $f)->label, $fields)));

        return ['level' => $award->level ?? ($parsed['level'] ?? null), 'award' => $award, 'fields' => array_map(fn ($f) => $f->label, $fields), 'broad' => $broad];
    }

    public static function engineeringWordings(): array
    {
        return [
            "Bachelor's Degree in Engineering" => ["Bachelor's Degree in Engineering", 'bachelor', null, ['Engineering']],
            'BEng' => ['BEng', 'bachelor', 'Bachelor of Engineering', []],
            'Bachelor of Engineering' => ['Bachelor of Engineering', 'bachelor', 'Bachelor of Engineering', ['Engineering']],
            'Degree in Mechanical Engineering' => ['Degree in Mechanical Engineering', 'degree', null, ['Mechanical Engineering']],
        ];
    }

    #[DataProvider('engineeringWordings')]
    public function test_the_four_engineering_wordings_land_in_the_same_place(string $text, string $level, ?string $award, array $fields): void
    {
        $c = $this->classify($text);

        $this->assertSame($level, $c['level'], $text);
        $this->assertSame($award, $c['award']?->label, $text);
        $this->assertSame($fields, $c['fields'], $text);

        // all four are about engineering: through the field named, or through the field the award name implies
        $broad = $c['broad'] ?: [DB::table('fields_of_study')->where('key', $c['award']?->field_key)->value('name')];
        $this->assertSame(['Engineering'], $broad, $text);
    }

    public function test_an_award_knows_the_field_its_name_implies_and_the_level(): void
    {
        $beng = $this->t()->find('award', 'BEng');

        $this->assertSame('bachelor', $beng->level);
        $this->assertSame('engineering', $beng->field_key);
        $this->assertNull($this->t()->find('award', 'BSc')->field_key);   // a BSc is not tied to one field
        $this->assertSame('masters', $this->t()->find('award', 'MBA')->level);
        $this->assertSame('postgraduate', $this->t()->find('award', 'Postgraduate Diploma')->level);
    }

    public function test_the_longest_wording_wins_and_a_stretch_of_text_is_used_once(): void
    {
        $this->assertSame(['Bachelor of Science in Nursing'], $this->names('award', 'Bachelor of Science in Nursing'));   // not also Bachelor of Science
        $this->assertSame(['Procurement and Supply Chain Management'], $this->names('field', 'Degree in Procurement and Supply Chain Management'));
        $this->assertSame(['Master of Business Administration'], $this->names('award', 'MBA'));
        $this->assertSame(['Bachelor of Science'], $this->names('award', 'B.Sc. degree'));
    }

    // ---- your brief, section 2: the Financial Accountant ad ----

    private const FINANCIAL_ACCOUNTANT = "Position: Financial Accountant\n\nRequirements:\n- Bachelor's Degree in Accounting or Finance.\n- ACCA or CIMA qualification is an added advantage.\n- Minimum 3 years of relevant experience.\n- Proficiency in Microsoft Excel.\n- Knowledge of financial reporting and budgeting.";

    public function test_the_financial_accountant_ad_resolves_to_the_expected_taxonomy_entries(): void
    {
        $ad = self::FINANCIAL_ACCOUNTANT;

        $this->assertSame(['ACCA', 'CIMA'], $this->names('professional', $ad));
        $this->assertSame(['Microsoft Excel', 'Financial reporting', 'Budgeting'], $this->names('skill', $ad));
        $this->assertSame(['Accounting', 'Finance'], $this->names('field', $ad));
        $this->assertSame(['Accounting and Finance'], array_values(array_unique(array_map(fn ($f) => $f->parent->label, $this->t()->mentions('field', $ad)))));
        $this->assertSame([], $this->names('award', $ad));        // "Bachelor's Degree" is a level (Phase 6), not a named award
        $this->assertSame([], $this->names('registration', $ad));

        $level = app(QualificationParser::class)->parse("Bachelor's Degree in Accounting or Finance.");
        $this->assertSame('bachelor', $level['level']);

        $occupation = $this->t()->occupation('Financial Accountant');
        $this->assertSame('2411', $occupation->code);
        $this->assertSame('Accountants', $occupation->label);
        $this->assertSame('24', $occupation->parent->code);
        $this->assertSame('Business and Administration Professionals', $occupation->parent->label);
        $this->assertSame('2', $occupation->parent->parent->code);
        $this->assertSame('Professionals', $occupation->parent->parent->label);

        $this->assertSame('K', $this->t()->find('industry', 'Finance')->code);
        $this->assertSame('Financial and insurance activities', $this->t()->find('industry', 'Finance')->label);
        $this->assertSame('standard', $this->t()->seniority('Financial Accountant')->key);
    }

    public function test_the_example_has_no_false_positives_from_ordinary_words(): void
    {
        $text = 'Candidates must react quickly to changes, tally the daily figures, keep a positive outlook and a word of mouth reputation. '
            .'The project will go live when the sap of the new tree is ready. Pastel colours are used. People who excel at what they do take the lead.';

        $this->assertSame([], $this->names('skill', $text));
        $this->assertSame([], $this->names('professional', $text));
        $this->assertSame([], $this->names('field', 'We need someone who can design, plan and manage things.'));
    }

    // ---- skills ----

    public static function skillCases(): array
    {
        return [
            'MS Excel' => ['Proficient in MS Excel and PowerPoint', ['Microsoft Excel', 'Microsoft PowerPoint']],
            'advanced excel' => ['Advanced Excel skills', ['Microsoft Excel']],
            'Excel capitalised' => ['Strong Excel spreadsheets', ['Microsoft Excel']],
            'office suite is one skill' => ['Microsoft Office Suite', ['Microsoft Office']],
            'Sage Pastel' => ['Experience with Sage Pastel', ['Sage']],
            'SAP in capitals' => ['Experience with SAP', ['SAP']],
            'C# and .NET' => ['Developer with C# and .NET experience', ['C#', '.NET']],
            'a word that contains a skill' => ['Sagebrush and Excelsior', []],
            'communication' => ['Excellent written and verbal communication skills', ['Communication']],
            'Microsoft Project vs project management' => ['Microsoft Project and project management', ['Microsoft Project', 'Project management']],
            'languages' => ['Fluent in English and Bemba', ['English', 'Bemba']],
            'financial skills' => ['Knowledge of IFRS, budgeting and payroll', ['IFRS', 'Budgeting', 'Payroll processing']],
            'health and safety' => ['Knowledge of occupational health and safety', ['Health and safety']],
            'R programming only with context' => ['Skills in R programming and Python', ['R programming', 'Python']],
            'bare R and Go' => ['Route R and Go there', []],
        ];
    }

    #[DataProvider('skillCases')]
    public function test_skills_are_found_by_their_wordings(string $text, array $expected): void
    {
        $this->assertSame($expected, $this->names('skill', $text), $text);
    }

    public function test_short_acronyms_match_only_in_capitals(): void
    {
        $this->assertSame(['SAP'], $this->names('skill', 'SAP'));
        $this->assertSame([], $this->names('skill', 'the sap rises in spring'));
        $this->assertSame(['SQL'], $this->names('skill', 'Strong SQL'));
        $this->assertSame([], $this->names('skill', 'sql'));
        $this->assertSame('SAP', $this->t()->find('skill', 'SAP')->label);
        $this->assertNull($this->t()->find('skill', 'sap'));
    }

    public function test_mentions_come_in_the_order_they_appear(): void
    {
        $this->assertSame(['Budgeting', 'Microsoft Excel', 'Financial reporting'], $this->names('skill', 'Budgeting, then Microsoft Excel, then financial reporting'));
        $this->assertSame(['IFRS', 'Budgeting', 'Payroll processing'], $this->names('skill', 'IFRS, budgeting and payroll'));   // a case-exact match keeps its place
        $this->assertSame(['Budgeting', 'SAP', 'Financial reporting'], $this->names('skill', 'Budgeting in SAP and financial reporting'));
    }

    // ---- professional qualifications and registrations ----

    public static function professionalCases(): array
    {
        return [
            ['Part qualified ACCA or CIMA', 'professional', ['ACCA', 'CIMA']],
            ['PMP certification preferred', 'professional', ['PMP']],
            ['CompTIA A+', 'professional', ['CompTIA A+']],
            ['PRINCE2 practitioner', 'professional', ['PRINCE2']],
            ['MCIPS or CIPS', 'professional', ['CIPS']],
            ['Registered with HPCZ', 'registration', ['HPCZ registration']],
            ['EIZ registered engineer', 'registration', ['EIZ registered engineer']],       // not also EIZ membership
            ['A member of ZICA', 'registration', ['ZICA membership']],
            ['Holds a LAZ practising certificate', 'registration', ['LAZ practising certificate']],
            ['A valid driving licence', 'registration', ['Valid driving licence']],
            ['Registered with the Teaching Council of Zambia', 'registration', ['TCZ registration']],
        ];
    }

    #[DataProvider('professionalCases')]
    public function test_professional_qualifications_and_registrations(string $text, string $kind, array $expected): void
    {
        $this->assertSame($expected, $this->names($kind, $text), $text);
    }

    // ---- fields of study ----

    public function test_fields_have_a_broad_field_and_a_broad_field_is_also_a_field(): void
    {
        $mech = $this->t()->find('field', 'Mechanical Engineering');
        $this->assertSame('Engineering', $mech->parent->label);

        $broad = $this->t()->find('field', 'Engineering');
        $this->assertNull($broad->parent);

        $this->assertSame('Information Technology and Computing', $this->t()->find('field', 'ICT')->label);
        $this->assertSame('Mathematics', $this->t()->find('field', 'Maths')->label);
        $this->assertSame('Law', $this->t()->find('field', 'LAW')->label);
        $this->assertSame(['Accounting', 'Finance'], $this->names('field', 'Accounting or Finance'));
        $this->assertSame(['Clinical Medicine'], $this->names('field', 'Diploma in Clinical Medicine'));
    }

    // ---- occupations and seniority ----

    public static function occupationCases(): array
    {
        return [
            ['Mining Engineer', '2146'],
            ['Senior Mining Engineer', '2146'],          // seniority words are ignored
            ['Primary School Teacher', '2341'],
            ['Registered Nurse', '2221'],
            ['Truck Driver', '8332'],
            ['Driver', '8322'],
            ['Cleaner', '9112'],
            ['Head of Finance', '1211'],
            ['Software Developer', '2512'],
            ['Procurement Officer', '3323'],
            ['Accounts Assistant', '3313'],
            ['Security Guard', '5414'],
        ];
    }

    #[DataProvider('occupationCases')]
    public function test_job_titles_find_their_occupation(string $title, string $code): void
    {
        $this->assertSame($code, $this->t()->occupation($title)?->code, $title);
    }

    public function test_a_title_that_matches_nothing_is_not_forced_into_an_occupation(): void
    {
        $this->assertNull($this->t()->occupation('Chief Vibe Officer'));
        $this->assertNull($this->t()->occupation(''));
        $this->assertNull($this->t()->occupation('Professionals')); // a group name is not a job title
    }

    public static function seniorityCases(): array
    {
        return [
            ['Financial Accountant', 'standard'],
            ['Senior Financial Accountant', 'senior'],
            ['Graduate Trainee', 'intern'],
            ['Junior Accountant', 'junior'],
            ['Finance Manager', 'manager'],
            ['Head of Finance', 'head'],
            ['Managing Director', 'director'],
            ['Chief Executive Officer', 'director'],
            ['Team Leader', 'senior'],
            ['Personal Assistant', 'standard'],   // "assistant" says nothing about level
            ['Head Chef', 'standard'],
            ['Senior Manager', 'manager'],        // the higher of the two
        ];
    }

    #[DataProvider('seniorityCases')]
    public function test_seniority_from_the_title(string $title, string $level): void
    {
        $this->assertSame($level, $this->t()->seniority($title)->key, $title);
    }

    // ---- industries ----

    public function test_industries_are_found_from_everyday_words(): void
    {
        $this->assertSame('B', $this->t()->find('industry', 'Mining')->code);
        $this->assertSame('K', $this->t()->find('industry', 'banking')->code);
        $this->assertSame('P', $this->t()->find('industry', 'University')->code);
        $this->assertSame('Q', $this->t()->find('industry', 'Hospital')->code);
        $this->assertSame(['Information and communication'], $this->names('industry', 'a telecommunications company'));
    }

    // ---- realistic ads: what is found, and what must not be ----

    public function test_realistic_ads_give_sensible_matches_and_no_false_ones(): void
    {
        $ngo = 'Programme Officer for community mobilisation and monitoring and evaluation. A degree in Social Sciences, Development Studies or Public Health. '
            .'Experience with donor reporting. Proficiency in MS Office, KoBoToolbox and DHIS2. Excellent communication and interpersonal skills. '
            .'A valid driving licence is an added advantage. Fluency in Bemba or Nyanja.';
        $mining = 'Kansanshi Mine seeks a Mining Engineer with a BEng in Mining Engineering, registered with EIZ, using Surpac or Datamine for open pit mine planning. '
            .'Drilling and blasting experience is an advantage. NEBOSH is preferred. Strong analytical skills.';
        $nurse = 'Registered Nurse. A Diploma in Nursing, registered with NMCZ, and experience in theatre or emergency nursing. Patient care and infection prevention and control are essential.';

        $skills = $this->names('skill', $ngo);
        foreach (['Community mobilisation', 'Monitoring and evaluation', 'Grants management', 'Microsoft Office', 'KoBoToolbox', 'DHIS2', 'Communication', 'Interpersonal skills', 'Bemba', 'Nyanja'] as $expected) {
            $this->assertContains($expected, $skills);
        }
        $this->assertNotContains('Vehicle driving', $skills);                          // "driving licence" is a registration, not a skill
        $this->assertSame(['Valid driving licence'], $this->names('registration', $ngo));
        $this->assertContains('Development Studies', $this->names('field', $ngo));

        $this->assertSame(['Bachelor of Engineering'], $this->names('award', $mining));
        $this->assertSame(['Mining Engineering'], $this->names('field', $mining));     // not also "Mining Technology" from the word "Mining" in the title
        $this->assertSame(['EIZ registered engineer'], $this->names('registration', $mining));
        $this->assertSame(['NEBOSH'], $this->names('professional', $mining));
        $this->assertEqualsCanonicalizing(['Mine planning', 'Surpac', 'Datamine', 'Drilling and blasting', 'Analytical skills'], $this->names('skill', $mining));

        $this->assertSame(['Nursing'], $this->names('field', $nurse));
        $this->assertSame(['NMCZ registration'], $this->names('registration', $nurse));
        $this->assertSame(['Patient care', 'Infection prevention and control'], $this->names('skill', $nurse));
    }

    // ---- using the index ----

    public function test_an_unknown_phrase_is_not_found_and_an_unknown_kind_is_an_error(): void
    {
        $this->assertNull($this->t()->find('skill', 'underwater basket weaving'));
        $this->assertSame([], $this->t()->mentions('skill', ''));

        $this->expectException(\InvalidArgumentException::class);
        $this->t()->find('colour', 'red');
    }

    public function test_a_persons_addition_is_found_after_the_index_is_refreshed(): void
    {
        $this->assertSame([], $this->names('skill', 'Experienced in beekeeping at scale'));

        DB::table('skills')->insert(['name' => 'Beekeeping at scale', 'type' => 'technical', 'category' => 'Agriculture', 'aliases' => json_encode(['commercial beekeeping']), 'origin' => 'person', 'created_at' => now(), 'updated_at' => now()]);
        $this->t()->flush();

        $this->assertSame(['Beekeeping at scale'], $this->names('skill', 'Experienced in commercial beekeeping'));
    }

    public function test_the_lookup_reads_and_never_writes(): void
    {
        $before = [DB::table('skills')->count(), DB::table('skills')->max('updated_at')];

        $this->t()->mentions('skill', self::FINANCIAL_ACCOUNTANT);
        $this->t()->occupation('Financial Accountant');

        $this->assertSame($before, [DB::table('skills')->count(), DB::table('skills')->max('updated_at')]);
    }
}
