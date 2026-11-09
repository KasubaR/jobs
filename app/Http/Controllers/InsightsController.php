<?php

namespace App\Http\Controllers;

use App\Analytics\AdvertisedDemand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Reports on advertised demand. Every figure goes through AdvertisedDemand so they all count the same way. */
class InsightsController extends Controller
{
    /** Providers recorded in the directory beside advertised demand. */
    public function supply(Request $request): View
    {
        $filters = $request->validate([
            'scope' => ['nullable', 'in:all,open'],
            'province' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('type', 'province')],
        ]);
        $scope = $filters['scope'] ?? 'all';
        $province = $filters['province'] ?? null;
        $demand = new AdvertisedDemand($scope, $province ? (int) $province : null);

        return view('insights.supply', [
            'filters' => ['scope' => $scope, 'province' => $province],
            'provinces' => DB::table('locations')->where('type', 'province')->orderBy('name')->pluck('name', 'id'),
            'report' => (new \App\Analytics\SupplyVsDemand($demand))->report(),
            'analysed' => $demand->counts()['analysed'],
            'minSample' => AdvertisedDemand::MIN_SAMPLE,
        ]);
    }

    /** How often one qualification or level is asked for, month by month. Posting date, so open and closed vacancies are both included. */
    public function trends(Request $request): View
    {
        $filters = $request->validate([
            'subject' => ['nullable', 'string', 'max:40'],
            'province' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('type', 'province')],
        ]);
        $province = $filters['province'] ?? null;
        $demand = new AdvertisedDemand('all', $province ? (int) $province : null);

        $levels = DB::table('qualification_levels')->orderBy('rank')->pluck('name', 'key');
        $qualifications = $demand->qualificationsAsked(30);

        $subject = $filters['subject'] ?? ($qualifications->isNotEmpty() ? 'qualification:'.$qualifications->first()->id : null);
        $parsed = null;
        $name = null;
        if ($subject && preg_match('/^(qualification|level):(.+)$/', $subject, $m)) {
            if ($m[1] === 'level' && isset($levels[$m[2]])) {
                $parsed = ['type' => 'level', 'key' => $m[2]];
                $name = $levels[$m[2]].' (any field)';
            } elseif ($m[1] === 'qualification' && ctype_digit($m[2]) && ($q = DB::table('directory_qualifications')->where('id', $m[2])->first())) {
                $parsed = ['type' => 'qualification', 'id' => (int) $q->id];
                $name = $q->name;
            }
        }

        return view('insights.trends', [
            'filters' => ['subject' => $subject, 'province' => $province],
            'provinces' => DB::table('locations')->where('type', 'province')->orderBy('name')->pluck('name', 'id'),
            'qualifications' => $qualifications,
            'levels' => $levels,
            'name' => $name,
            'months' => $parsed ? $demand->trend($parsed) : collect(),
            'minMonth' => AdvertisedDemand::TREND_MIN_MONTH,
            'shift' => AdvertisedDemand::MIX_SHIFT_POINTS,
        ]);
    }

    /** Qualifications asked for within one occupation or one industry. */
    public function group(Request $request, string $dimension): View
    {
        abort_unless(in_array($dimension, AdvertisedDemand::DIMENSIONS, true), 404);

        $filters = $request->validate([
            'scope' => ['nullable', 'in:all,open'],
            'province' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('type', 'province')],
            'level' => ['nullable', 'in:submajor,unit'],
            'group' => ['nullable', 'string', 'max:10'],
        ]);
        $scope = $filters['scope'] ?? 'all';
        $province = $filters['province'] ?? null;
        $level = $filters['level'] ?? 'submajor';

        $demand = new AdvertisedDemand($scope, $province ? (int) $province : null);
        $groups = $demand->groups($dimension, $level);
        $selected = isset($filters['group']) ? $groups['groups']->firstWhere('key', $filters['group']) : null;

        return view('insights.group', [
            'dimension' => $dimension,
            'filters' => ['scope' => $scope, 'province' => $province, 'level' => $level, 'group' => $filters['group'] ?? null],
            'provinces' => DB::table('locations')->where('type', 'province')->orderBy('name')->pluck('name', 'id'),
            'groups' => $groups,
            'selected' => $selected,
            'detail' => $selected ? $demand->qualificationsIn($dimension, $selected->key, $level) : null,
            'minSample' => AdvertisedDemand::MIN_SAMPLE,
        ]);
    }

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'scope' => ['nullable', 'in:all,open'],
            'province' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('type', 'province')],
        ]);
        $scope = $filters['scope'] ?? 'all';
        $province = $filters['province'] ?? null;

        $demand = new AdvertisedDemand($scope, $province ? (int) $province : null);

        return view('insights.index', [
            'filters' => ['scope' => $scope, 'province' => $province],
            'provinces' => DB::table('locations')->where('type', 'province')->orderBy('name')->pluck('name', 'id'),
            'counts' => $demand->counts(),
            'asked' => $demand->qualificationsAsked(),
            'levels' => $demand->levels(),
            'entry' => $demand->entryLevel(),
            'pairs' => $demand->professionalWithDegrees(),
            'minSample' => AdvertisedDemand::MIN_SAMPLE,
        ]);
    }
}
