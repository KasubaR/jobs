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
