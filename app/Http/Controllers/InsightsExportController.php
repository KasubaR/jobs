<?php

namespace App\Http\Controllers;

use App\Analytics\AdvertisedDemand;
use App\Analytics\SupplyVsDemand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every Insights report as a CSV, so a figure can be checked outside the app. Each file carries its denominator and a
 * "reliable" column (false = fewer than the minimum sample, so no share is given), and every row says what it measures.
 */
class InsightsExportController extends Controller
{
    private const REPORTS = ['qualifications', 'levels', 'entry', 'professional', 'group', 'trends', 'supply'];

    public function download(Request $request, string $report): StreamedResponse
    {
        abort_unless(in_array($report, self::REPORTS, true), 404);

        $f = $request->validate([
            'scope' => ['nullable', 'in:all,open'],
            'province' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('type', 'province')],
            'dimension' => ['nullable', 'in:occupation,industry'],
            'level' => ['nullable', 'in:submajor,unit'],
            'group' => ['nullable', 'string', 'max:10'],
            'subject' => ['nullable', 'string', 'max:40'],
        ]);
        $province = ($f['province'] ?? null) ? (int) $f['province'] : null;
        $d = new AdvertisedDemand($report === 'trends' ? 'all' : ($f['scope'] ?? 'all'), $province);

        [$header, $rows] = match ($report) {
            'qualifications' => $this->qualifications($d),
            'levels' => $this->levels($d),
            'entry' => $this->entry($d),
            'professional' => $this->professional($d),
            'group' => $this->group($d, $f),
            'trends' => $this->trends($d, $f['subject'] ?? null),
            'supply' => $this->supply($d),
        };

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // so a spreadsheet reads the UTF-8
            fputcsv($out, $header, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, array_map([$this, 'safe'], $row), ',', '"', '');
            }
            fclose($out);
        }, "advertised-demand-{$report}-".now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A cell starting with = + - @ would run as a formula in a spreadsheet. */
    private function safe(mixed $cell): mixed
    {
        return is_string($cell) && preg_match('/^[=+\-@\t\r]/', $cell) ? "'".$cell : $cell;
    }

    private function bool(bool $b): string
    {
        return $b ? 'yes' : 'no';
    }

    private function qualifications(AdvertisedDemand $d): array
    {
        $read = $d->counts()['analysed'];

        return [['qualification', 'kind', 'vacancies', 'required_vacancies', 'share_percent', 'vacancies_read', 'reliable'],
            $d->qualificationsAsked(1000)->map(fn ($r) => [$r->name, $r->kind, $r->vacancies, $r->required, $r->share, $read, $this->bool($r->reliable)])->all()];
    }

    private function levels(AdvertisedDemand $d): array
    {
        $read = $d->counts()['analysed'];

        return [['education_level', 'vacancies', 'share_percent', 'vacancies_read', 'reliable'],
            $d->levels()->map(fn ($r) => [$r->name, $r->vacancies, $r->share, $read, $this->bool($r->reliable)])->all()];
    }

    private function entry(AdvertisedDemand $d): array
    {
        $e = $d->entryLevel();
        $rows = $e['levels']->map(fn ($r) => ['education_level', $r->name, $r->vacancies, $r->share, $e['vacancies'], $this->bool($r->reliable && $e['reliable'])])
            ->concat($e['qualifications']->map(fn ($r) => ['qualification', $r->name, $r->vacancies, $r->share, $e['vacancies'], $this->bool($r->reliable && $e['reliable'])]));

        return [['measure', 'name', 'vacancies', 'share_percent', 'entry_level_vacancies', 'reliable'], $rows->all()];
    }

    private function professional(AdvertisedDemand $d): array
    {
        $p = $d->professionalWithDegrees();

        return [['professional_qualification', 'vacancies', 'also_ask_degree_or_higher', 'share_percent', 'all_degree_vacancies', 'reliable'],
            $p['qualifications']->map(fn ($r) => [$r->name, $r->vacancies, $r->with_degree, $r->share, $p['degree_vacancies'], $this->bool($r->reliable)])->all()];
    }

    private function group(AdvertisedDemand $d, array $f): array
    {
        $dimension = $f['dimension'] ?? 'occupation';
        $level = $f['level'] ?? 'submajor';
        $header = [$dimension, 'qualification', 'kind', 'vacancies', 'required_vacancies', 'share_of_group_percent', 'share_overall_percent', 'group_vacancies', 'reliable'];

        $rows = [];
        foreach ($d->groups($dimension, $level)['groups'] as $g) {
            if (isset($f['group']) && (string) $g->key !== $f['group']) {
                continue;
            }
            $in = $d->qualificationsIn($dimension, (string) $g->key, $level, 1000);
            foreach ($in['qualifications'] as $r) {
                $rows[] = [$g->name, $r->name, $r->kind, $r->vacancies, $r->required, $r->share, $r->overall_share, $in['vacancies'], $this->bool($r->reliable)];
            }
        }

        return [$header, $rows];
    }

    private function trends(AdvertisedDemand $d, ?string $subject): array
    {
        $parsed = null;
        if ($subject && preg_match('/^(qualification|level):(.+)$/', $subject, $m)) {
            $parsed = $m[1] === 'level' ? ['type' => 'level', 'key' => $m[2]] : (ctype_digit($m[2]) ? ['type' => 'qualification', 'id' => (int) $m[2]] : null);
        }
        $parsed ??= ($first = $d->qualificationsAsked(1)->first()) ? ['type' => 'qualification', 'id' => $first->id] : null;

        $name = $parsed ? ($parsed['type'] === 'level' ? ($parsed['key'].' (any field)') : DB::table('directory_qualifications')->where('id', $parsed['id'])->value('name')) : '';

        return [['subject', 'month', 'vacancies_read', 'ask_for_it', 'share_percent', 'under_minimum_month', 'source_mix_shift_points', 'different_source_mix'],
            $parsed ? $d->trend($parsed)->map(fn ($r) => [$name, $r->month, $r->vacancies, $r->asking, $r->share, $this->bool($r->thin), $r->mix_shift, $this->bool($r->mix_changed)])->all() : []];
    }

    private function supply(AdvertisedDemand $d): array
    {
        $r = (new SupplyVsDemand($d))->report();

        return [['qualification', 'kind', 'vacancies_asking', 'share_percent', 'providers_recorded', 'programmes_recorded', 'verified_programmes', 'note'],
            $r['rows']->map(fn ($x) => [$x->name, $x->kind, $x->vacancies, $x->share, $x->providers, $x->programmes, $x->verified,
                ['none_recorded' => 'no provider recorded in the directory', 'not_asked' => 'offered; not asked for in vacancies read', 'recorded' => 'provider recorded'][$x->status]])->all()];
    }
}
