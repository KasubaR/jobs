<?php

namespace App\Standardization;

use Illuminate\Support\Facades\DB;

/**
 * The starting dictionary. It is data: installed into `term_mappings` and `locations`, then corrected by a person
 * (the "values to map" screen). Installing again adds what is missing and never overwrites a person's correction.
 */
class DefaultTerms
{
    /** Qualification levels, lowest to highest, with the standard label and a rank for comparing. */
    public const LEVELS = [
        'school' => ['Grade 12', 1],
        'certificate' => ['Certificate', 2],
        'diploma' => ['Diploma', 3],
        'degree' => ['Degree', 4],
        'bachelor' => ["Bachelor's Degree", 4],
        'postgraduate' => ['Postgraduate Qualification', 5],
        'masters' => ["Master's Degree", 5],
        'phd' => ['Doctorate (PhD)', 6],
        'professional' => ['Professional Qualification', null],
    ];

    public const EMPLOYMENT_TYPES = [
        'full_time' => 'Full-time', 'part_time' => 'Part-time', 'permanent' => 'Permanent', 'contract' => 'Contract',
        'temporary' => 'Temporary', 'internship' => 'Internship', 'volunteer' => 'Volunteer', 'consultancy' => 'Consultancy',
    ];

    /** Phrase (as normalized) => level. Longest phrases are matched first, so "grade 12 certificate" is one school level, not two. */
    private const QUALIFICATION_PHRASES = [
        'school' => ['grade 12', 'grade twelve', 'grade 12 certificate', 'school certificate', 'secondary school', 'high school', 'o level', 'o levels', 'a level', 'a levels', 'gce', 'matric', 'form 5', 'form v'],
        'certificate' => ['certificate', 'cert', 'craft certificate', 'technician certificate', 'trade test', 'short course'],
        'diploma' => ['diploma', 'advanced diploma', 'higher diploma', 'national diploma', 'ordinary diploma', 'dip'],
        'bachelor' => ['bachelors', 'bachelor', 'bsc', 'ba', 'beng', 'bcom', 'btech', 'bed', 'llb', 'mbchb', 'honours degree', 'hons', 'undergraduate degree', 'first degree'],
        'degree' => ['degree'],
        'postgraduate' => ['postgraduate', 'post graduate'],
        'masters' => ['masters', 'master', 'msc', 'mba', 'ma', 'mphil', 'meng', 'mcom', 'masters degree'],
        'phd' => ['phd', 'doctorate', 'doctoral', 'dphil', 'doctor of philosophy'],
        'professional' => ['acca', 'cima', 'cpa', 'zica', 'chartered accountant', 'cfa', 'cisa', 'cism', 'pmp', 'prince2', 'professional qualification'],
    ];

    private const EMPLOYMENT_PHRASES = [
        'full_time' => ['full time', 'fulltime', 'ft', 'full time employment'],
        'part_time' => ['part time', 'parttime', 'pt', 'part time employment'],
        'permanent' => ['permanent', 'permanent employment', 'permanent position', 'pensionable', 'pensionable position'],
        'contract' => ['contract', 'contractual', 'fixed term', 'fixed term contract', 'short term contract', 'contract employment', 'contract position'],
        'temporary' => ['temporary', 'temp', 'seasonal', 'casual'],
        'internship' => ['internship', 'intern', 'internships', 'attachment', 'industrial attachment', 'apprenticeship', 'traineeship', 'graduate trainee'],
        'volunteer' => ['volunteer', 'volunteering', 'voluntary'],
        'consultancy' => ['consultancy', 'consultant', 'freelance', 'freelancer'],
    ];

    /** Words that name no particular place. Recognized, so they are not "unrecognized", but they set no location. */
    private const NON_SPECIFIC_PLACES = ['zambia', 'republic of zambia', 'nationwide', 'countrywide', 'country wide', 'various', 'multiple locations', 'all provinces', 'anywhere in zambia', 'remote', 'home based'];

    private const LOCATION_ALIASES = ['copper belt' => 'Copperbelt', 'north west' => 'North-Western', 'nw province' => 'North-Western'];

    /** Province => its main towns. */
    public const PLACES = [
        'Central' => ['Kabwe', 'Kapiri Mposhi', 'Mkushi', 'Serenje', 'Mumbwa', 'Chibombo', 'Itezhi-Tezhi', 'Chisamba', 'Luano'],
        'Copperbelt' => ['Ndola', 'Kitwe', 'Chingola', 'Mufulira', 'Luanshya', 'Chililabombwe', 'Kalulushi', 'Lufwanyama', 'Masaiti', 'Mpongwe'],
        'Eastern' => ['Chipata', 'Petauke', 'Katete', 'Lundazi', 'Nyimba', 'Chadiza', 'Mambwe', 'Vubwi'],
        'Luapula' => ['Mansa', 'Samfya', 'Nchelenge', 'Kawambwa', 'Mwense', 'Milenge'],
        'Lusaka' => ['Lusaka', 'Kafue', 'Chilanga', 'Chongwe', 'Rufunsa', 'Luangwa', 'Chirundu', 'Shibuyunji'],
        'Muchinga' => ['Chinsali', 'Mpika', 'Nakonde', 'Isoka', 'Chama', 'Kanchibiya', 'Mafinga'],
        'Northern' => ['Kasama', 'Mbala', 'Mpulungu', 'Mporokoso', 'Luwingu', 'Mungwi', 'Chilubi', 'Kaputa', 'Nsama'],
        'North-Western' => ['Solwezi', 'Kasempa', 'Mwinilunga', 'Zambezi', 'Mufumbwe', 'Kabompo', 'Chavuma', 'Ikelenge', 'Manyinga'],
        'Southern' => ['Livingstone', 'Choma', 'Mazabuka', 'Monze', 'Kalomo', 'Siavonga', 'Gwembe', 'Sinazongwe', 'Namwala', 'Pemba', 'Kazungula', 'Zimba', 'Chikankata'],
        'Western' => ['Mongu', 'Kaoma', 'Senanga', 'Sesheke', 'Lukulu', 'Kalabo', 'Mitete', 'Nalolo', 'Shangombo'],
    ];

    public static function install(): void
    {
        $now = now();

        foreach (self::PLACES as $province => $towns) {
            $parent = self::place($province, 'province', null);
            foreach ($towns as $town) {
                self::place($town, 'town', $parent);
            }
        }

        $rows = [];
        $add = function (string $kind, string $phrase, ?string $value, ?array $meta = null) use (&$rows, $now) {
            $rows[] = ['kind' => $kind, 'normalized' => Text::normalize($phrase), 'standard_value' => $value,
                'meta' => $meta === null ? null : json_encode($meta), 'origin' => 'seed', 'created_at' => $now, 'updated_at' => $now];
        };

        foreach (self::QUALIFICATION_PHRASES as $level => $phrases) {
            foreach ($phrases as $phrase) {
                $add('qualification_level', $phrase, $level);
            }
        }

        foreach (self::EMPLOYMENT_PHRASES as $key => $phrases) {
            foreach ($phrases as $phrase) {
                $add('employment_type', $phrase, $key);
            }
        }

        foreach (self::NON_SPECIFIC_PLACES as $phrase) {
            $add('location', $phrase, null, ['ignore' => true]);
        }

        foreach (self::LOCATION_ALIASES as $alias => $name) {
            $id = DB::table('locations')->where('name', $name)->where('type', 'province')->value('id');
            $id && $add('location', $alias, $name, ['location_id' => $id]);
        }

        foreach ($rows as $row) {
            DB::table('term_mappings')->insertOrIgnore($row); // a person's row with the same text is kept
        }
    }

    private static function place(string $name, string $type, ?int $parent): int
    {
        $existing = DB::table('locations')->where('name', $name)->where('type', $type)->where('parent_id', $parent)->value('id');

        return $existing ?? DB::table('locations')->insertGetId(['name' => $name, 'type' => $type, 'parent_id' => $parent, 'created_at' => now(), 'updated_at' => now()]);
    }
}
