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
