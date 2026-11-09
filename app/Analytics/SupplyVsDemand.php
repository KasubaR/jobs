<?php

namespace App\Analytics;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Providers recorded in the directory beside advertised demand.
 *
 * The supply side is only what has been entered into the directory (usually from a CSV), so "no provider recorded"
 * means none is recorded here, never that none exists. It is a prompt to look, not a finding. Nothing here divides
 * vacancies by providers: that ratio would pretend both sides are complete.
 */
class SupplyVsDemand
{
    public function __construct(private AdvertisedDemand $demand) {}

    /**
     * @return array{rows: Collection, asked: int, asked_with_provider: int, programmes: int, providers: int, verified_programmes: int}
     */
    public function report(): array
    {
        $demand = $this->demand->qualificationsAsked(1000);

        $supply = DB::table('programmes')->groupBy('qualification_id')
            ->selectRaw('qualification_id, count(*) as programmes, count(distinct provider_id) as providers, sum(case when verified_at is not null then 1 else 0 end) as verified')
            ->get()->keyBy('qualification_id');

        $rows = $demand->map(function ($r) use ($supply) {
            $s = $supply[$r->id] ?? null;
            $r->programmes = (int) ($s->programmes ?? 0);
            $r->providers = (int) ($s->providers ?? 0);
            $r->verified = (int) ($s->verified ?? 0);
            $r->status = $r->providers > 0 ? 'recorded' : 'none_recorded';

            return $r;
        });

        // offered by a recorded provider, but not asked for in any vacancy read
        $unasked = $supply->keys()->diff($demand->pluck('id'));
        $extra = DB::table('directory_qualifications')->whereIn('id', $unasked)->get(['id', 'name', 'kind'])->map(function ($q) use ($supply) {
            $s = $supply[$q->id];

            return (object) ['id' => $q->id, 'name' => $q->name, 'kind' => $q->kind, 'vacancies' => 0, 'required' => 0, 'share' => null, 'reliable' => false,
                'programmes' => (int) $s->programmes, 'providers' => (int) $s->providers, 'verified' => (int) $s->verified, 'status' => 'not_asked'];
        })->sortByDesc('providers')->values();

        return [
            'rows' => $rows->concat($extra)->values(),
            'asked' => $rows->count(),
            'asked_with_provider' => $rows->where('providers', '>', 0)->count(),
            'programmes' => (int) $supply->sum('programmes'),
            'providers' => (int) DB::table('programmes')->distinct()->count('provider_id'),
            'verified_programmes' => (int) $supply->sum('verified'),
        ];
    }
}
