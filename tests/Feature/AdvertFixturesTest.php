<?php

namespace Tests\Feature;

use App\Ai\Extraction\ExtractionValidator;
use App\Ai\Extraction\QualificationSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The test set for the AI extraction prompt. These tests do not call any AI: they prove the set itself is sound, so a
 * later score means something. Every written answer must pass the same checker an AI answer must pass, unchanged.
 */
class AdvertFixturesTest extends TestCase
{
    private const DIR = __DIR__.'/../../database/evaluation/adverts';

    private static function manifest(): array
    {
        return require self::DIR.'/manifest.php';
    }

    public static function adverts(): array
    {
        return collect(self::manifest())->mapWithKeys(fn ($a) => [$a['file'] => [$a]])->all();
    }

    private function text(array $ad): string
    {
        return file_get_contents(self::DIR.'/'.$ad['file']);
    }

    #[DataProvider('adverts')]
    public function test_the_advert_file_is_clean_plain_text(array $ad): void
    {
        $text = $this->text($ad);

        $this->assertGreaterThan(500, mb_strlen($text), 'The advert is too short to be a whole advert.');
        foreach (['Skip to content', 'Recent Notifications', 'Webpushr', 'Copyright © Job Search', 'Post a Job'] as $chrome) {
            $this->assertStringNotContainsString($chrome, $text, "page navigation ({$chrome}) is in the advert text");
        }
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text, 'an email address is in the advert text');
        $this->assertDoesNotMatchRegularExpression('/<\/?[a-z][^>]*>/i', $text, 'markup is in the advert text');
    }

    #[DataProvider('adverts')]
    public function test_the_written_answer_passes_the_checker_with_nothing_dropped_or_changed(array $ad): void
    {
        $result = (new ExtractionValidator)->validate($ad['expected'], $this->text($ad));

        $this->assertTrue($result->ok);
        $this->assertSame([], array_map(fn ($x) => $x['section'].': '.$x['reason'].' '.json_encode($x['item']['name'] ?? $x['item']['years_min'] ?? ''), $result->rejected), 'items the checker rejected');
        $this->assertSame([], $result->corrections, 'things the checker had to correct');

        foreach (['qualifications', 'professional_registrations', 'experience_requirements'] as $section) {
            $this->assertSame(
                array_map(fn ($i) => [$i['name'] ?? $i['years_min'], $i['requirement_type'], $i['alternative_group']], $ad['expected'][$section]),
                array_map(fn ($i) => [$i['name'] ?? $i['years_min'], $i['requirement_type'], $i['alternative_group']], $result->items($section)),
                "{$section} changed on the way through the checker",
            );
        }
        $this->assertSame($ad['expected']['qualification_found'], $result->data['qualification_found']);
    }

    public function test_every_sector_the_brief_names_is_covered_by_real_adverts(): void
    {
        $bySector = collect(self::manifest())->groupBy('sector')->map->count();

        foreach (['healthcare', 'engineering', 'finance', 'education', 'administration'] as $sector) {
            $this->assertGreaterThanOrEqual(2, $bySector[$sector] ?? 0, "too few {$sector} adverts");
        }
    }

    public function test_a_third_is_held_back_across_several_sectors(): void
    {
        $holdout = collect(self::manifest())->where('split', 'holdout');

        $this->assertGreaterThanOrEqual(6, $holdout->count());
        $this->assertLessThanOrEqual(8, $holdout->count());
        $this->assertGreaterThanOrEqual(4, $holdout->pluck('sector')->unique()->count());
        $this->assertSame([], collect(self::manifest())->pluck('split')->diff(['tune', 'holdout'])->all());
    }

    public function test_the_set_has_the_awkward_shapes_the_brief_needs(): void
    {
        $all = collect(self::manifest());
        $types = $all->flatMap(fn ($a) => collect(['qualifications', 'professional_registrations', 'experience_requirements'])
            ->flatMap(fn ($s) => collect($a['expected'][$s])->pluck('requirement_type')))->unique()->all();

        foreach (['mandatory', 'preferred', 'advantage'] as $type) {
            $this->assertContains($type, $types, "nothing in the set is marked {$type}");
        }
        $this->assertTrue($all->contains(fn ($a) => $a['expected']['qualification_found'] === false), 'no advert without a qualification (the invention trap)');
        $this->assertTrue($all->contains(fn ($a) => collect($a['expected']['qualifications'])->contains(fn ($q) => $q['alternative_group'] !== null)), 'no either-or group');
        $this->assertSame(['08-multiple-positions.txt', '18-lecturers.txt'], $all->where('multi_position', true)->pluck('file')->all());
    }

    public function test_the_files_and_the_manifest_agree(): void
    {
        $files = collect(glob(self::DIR.'/[0-9][0-9]-*.txt'))->map(fn ($f) => basename($f))->sort()->values()->all();
        $listed = collect(self::manifest())->pluck('file')->sort()->values()->all();

        $this->assertSame($files, $listed);
        $this->assertSame(count($listed), count(array_unique(collect(self::manifest())->pluck('title')->all())));
    }
}
