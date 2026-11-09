@extends('layouts.app')
@section('title', 'Overview')
@section('heading', 'Overview')
@section('subheading', 'How collection is going · ' . now()->format('l, j F Y'))
@section('content')
@php
    // Small trend chip: compares this week with the one before. Only used where real history exists.
    $chip = function (array $t) {
        $d = $t['now'] - $t['before'];
        if ($t['before'] === 0) {
            return ['flat', $t['now'] > 0 ? 'New this week' : 'No change', ''];
        }
        $pct = round(abs($d) / $t['before'] * 100);

        return [$d > 0 ? 'up' : ($d < 0 ? 'down' : 'flat'), ($d > 0 ? '+' : ($d < 0 ? '−' : '')).$pct.'%', 'vs previous 7 days ('.$t['before'].')'];
    };
    $spark = function (array $v, int $w = 120, int $h = 34) {
        $max = max(1, max($v));
        $n = max(1, count($v) - 1);
        $pts = [];
        foreach ($v as $i => $y) {
            $pts[] = round($i / $n * $w, 1).','.round($h - 3 - ($y / $max) * ($h - 6), 1);
        }

        return implode(' ', $pts);
    };
    [$adsC, $adsT, $adsS] = $chip($adsTrend);
    [$runC, $runT, $runS] = $chip($runsTrend);
@endphp

@if ($atRisk > 0)
  <div class="alert warn" role="alert"><b>{{ number_format($atRisk) }} {{ Str::plural('ad', $atRisk) }} still {{ $atRisk === 1 ? 'waits' : 'wait' }} to be processed and {{ $atRisk === 1 ? 'is' : 'are' }} within 30 days of the {{ config('job_intel.raw_retention_days') }}-day limit.</b>
    Raw ad text is cleared at that limit, so an ad that has not been processed by then keeps only its title, link and dates.</div>
@endif
@if ($processFailed > 0)
  <div class="alert bad" role="alert">{{ number_format($processFailed) }} {{ Str::plural('ad', $processFailed) }} failed during processing. Run <code>php artisan processing:dispatch --failed</code> to try them again.</div>
@endif
@if ($processingOff)
  <div class="alert warn" role="status"><b>Processing is switched off, so {{ number_format($waiting) }} {{ Str::plural('ad', $waiting) }} {{ $waiting === 1 ? 'is' : 'are' }} waiting and no jobs are being made.</b>
    Set <code>PROCESSING_ENABLED=true</code> in the <code>.env</code> file, then run <code>php artisan processing:dispatch</code> once to take in the ads already stored.</div>
@endif

<section class="g4 st">
  <div class="card kpi">
    <span class="kl">Ads collected</span>
    <b class="kv">{{ number_format($adsTotal) }}</b>
    <div class="kc"><span class="chip {{ $adsC }}">@if ($adsC === 'up')▲@elseif ($adsC === 'down')▼@endif {{ $adsT }}</span><small>{{ $adsTrend['now'] }} in the last 7 days {{ $adsS ? '· '.$adsS : '' }}</small></div>
    <svg class="spark" viewBox="0 0 120 34" preserveAspectRatio="none" aria-hidden="true"><polyline points="{{ $spark($adsTrend['spark']) }}"/></svg>
  </div>
  <div class="card kpi">
    <span class="kl">Collection runs</span>
    <b class="kv">{{ number_format($runCount) }}</b>
    <div class="kc"><span class="chip {{ $runC }}">@if ($runC === 'up')▲@elseif ($runC === 'down')▼@endif {{ $runT }}</span><small>{{ $runOk }} completed in the last 7 days {{ $runS ? '· '.$runS : '' }}</small></div>
    <svg class="spark" viewBox="0 0 120 34" preserveAspectRatio="none" aria-hidden="true"><polyline points="{{ $spark($runsTrend['spark']) }}"/></svg>
  </div>
  <div class="card kpi">
    <span class="kl">Active sources</span>
    <b class="kv">{{ $activeSources }}</b>
    <div class="kc"><small>of {{ $totalSources }} registered · {{ $approved }} approved</small></div>
    <div class="tr"><i style="width:{{ $totalSources ? round($activeSources / $totalSources * 100) : 0 }}%"></i></div>
  </div>
  <div class="card kpi">
    <span class="kl">Waiting to be processed</span>
    <b class="kv">{{ number_format($waiting) }}</b>
    <div class="kc"><small>{{ number_format($rawKept) }} with raw text kept · {{ $adsToday }} new in 24 hours</small></div>
    <div class="tr {{ $waiting ? 'amber' : '' }}"><i style="width:{{ $adsTotal ? round($waiting / $adsTotal * 100) : 0 }}%"></i></div>
  </div>
</section>

<section class="split">
  <div class="card">
    <div class="hd"><div><h3>New ads per day</h3><p class="sub">Last 7 days, with the 7 days before in grey</p></div>
      <div class="legend"><span><i class="lg teal"></i>This week</span><span><i class="lg grey"></i>Before</span></div></div>
    @php
        $W = 560; $H = 190; $pad = 26;
        $cur = $days->pluck('ads')->all();
        $prev = array_slice($adsTrend['spark'], 0, 7);
        $top = max(4, max(max($cur), max($prev)));
        $xy = fn (array $v) => collect($v)->map(fn ($y, $i) => round($pad + $i / 6 * ($W - 2 * $pad), 1).','.round($H - $pad - ($y / $top) * ($H - 2 * $pad), 1))->implode(' ');
    @endphp
    <svg class="line" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="New ads per day for the last 7 days">
      @foreach ([0, .5, 1] as $g)
        @php $gy = round($H - $pad - $g * ($H - 2 * $pad), 1); @endphp
        <line x1="{{ $pad }}" x2="{{ $W - $pad }}" y1="{{ $gy }}" y2="{{ $gy }}" class="grid"/>
        <text x="0" y="{{ $gy + 4 }}" class="ax">{{ round($top * $g) }}</text>
      @endforeach
      <polyline class="prev" points="{{ $xy($prev) }}"/>
      <polyline class="cur" points="{{ $xy($cur) }}"/>
      @foreach ($cur as $i => $v)
        <circle class="dot" cx="{{ round($pad + $i / 6 * ($W - 2 * $pad), 1) }}" cy="{{ round($H - $pad - ($v / $top) * ($H - 2 * $pad), 1) }}" r="3.5"><title>{{ $days[$i]['label'] }}: {{ $v }} new</title></circle>
        <text x="{{ round($pad + $i / 6 * ($W - 2 * $pad), 1) }}" y="{{ $H - 6 }}" class="ax mid">{{ \Illuminate\Support\Str::before($days[$i]['label'], ' ') }}</text>
      @endforeach
    </svg>
  </div>
  <div class="card">
    <div class="hd"><div><h3>Sources by access</h3><p class="sub">Decisions on {{ $totalSources }} registered sources</p></div></div>
    @php
        $labels = ['approved' => 'Approved', 'pending' => 'Pending', 'restricted' => 'Restricted', 'blocked' => 'Blocked'];
        $shades = ['approved' => 'var(--teal)', 'pending' => '#cbd5e1', 'restricted' => '#94a3b8', 'blocked' => '#64748b'];
        $off = 0;
    @endphp
    @if ($totalSources)
      <div class="donut">
        <svg viewBox="0 0 36 36" role="img" aria-label="Sources by access decision">
          <circle cx="18" cy="18" r="15.9155" class="ring"/>
          @foreach ($accessCounts as $status => $count)
            @php $p = $count / $totalSources * 100; @endphp
            <circle cx="18" cy="18" r="15.9155" class="seg" stroke="{{ $shades[$status] ?? '#94a3b8' }}" stroke-dasharray="{{ round($p, 2) }} {{ round(100 - $p, 2) }}" stroke-dashoffset="{{ round(25 - $off, 2) }}"/>
            @php $off += $p; @endphp
          @endforeach
        </svg>
        <div class="dc"><b>{{ $approved }}</b><span>approved</span></div>
      </div>
      <ul class="dl">
        @foreach ($accessCounts as $status => $count)
          <li><i class="lg" style="background:{{ $shades[$status] ?? '#94a3b8' }}"></i>{{ $labels[$status] ?? ucfirst(str_replace('_', ' ', $status)) }}<b>{{ $count }}</b></li>
        @endforeach
      </ul>
    @else
      <p class="empty">No sources registered yet.</p>
    @endif
  </div>
</section>

<section class="split">
  <div class="card">
    @php
        $jobsTotal = $jobStatus->sum();
        $jobsMax = max(1, (int) $jobStatus->max());
        $statusNames = ['published' => 'Published', 'new' => 'New (waiting for review)', 'expired' => 'Expired', 'closed' => 'Closed', 'duplicate' => 'Duplicate', 'rejected' => 'Rejected'];
    @endphp
    <div class="hd"><div><h3>Jobs by status</h3><p class="sub">{{ number_format($jobsTotal) }} {{ Str::plural('job', $jobsTotal) }} in the clean dataset, each vacancy counted once</p></div><a class="vl" href="{{ route('jobs.index') }}">View all &rsaquo;</a></div>
    @if ($jobsTotal === 0)
      <p class="empty">No jobs yet. They appear once processing is switched on and ads have been collected.</p>
    @else
      @foreach ($statusNames as $key => $label)
        @php $n = (int) ($jobStatus[$key] ?? 0); @endphp
        <div class="rm"><div class="h"><a class="name" href="{{ route('jobs.index', ['status' => $key]) }}">{{ $label }}</a><em>{{ number_format($n) }}</em></div>
          <div class="bar"><i class="{{ $key === 'published' ? 'hi' : '' }}" style="width:{{ $n ? max(3, round($n / $jobsMax * 100)) : 0 }}%"></i></div></div>
      @endforeach
    @endif
  </div>
  <div class="card">
    <div class="hd"><div><h3>Waiting for you</h3><p class="sub">Decisions only a person can make</p></div></div>
    <div class="act"><div class="ic {{ $jobsToReview ? 'warn' : 'ok' }}"><svg class="i"><use href="#eye"/></svg></div>
      <div><a class="name" href="{{ route('jobs.index', ['review' => 'needs_review']) }}"><b>{{ $jobsToReview }}</b> {{ Str::plural('job', $jobsToReview) }} to review</a><br><small>Too little could be read from the ad</small></div></div>
    <div class="act"><div class="ic {{ $possibleDuplicates ? 'warn' : 'ok' }}"><svg class="i"><use href="#users"/></svg></div>
      <div><a class="name" href="{{ route('duplicates.index') }}"><b>{{ $possibleDuplicates }}</b> possible {{ Str::plural('duplicate', $possibleDuplicates) }}</a><br><small>Same vacancy or not?</small></div></div>
    <div class="act"><div class="ic {{ $valuesToMap ? 'warn' : 'ok' }}"><svg class="i"><use href="#bars"/></svg></div>
      <div><a class="name" href="{{ route('values.index') }}"><b>{{ $valuesToMap }}</b> {{ Str::plural('value', $valuesToMap) }} to map</a><br><small>Text the rules could not standardize</small></div></div>
  </div>
</section>

<section class="split">
  <div class="card">
    <div class="hd"><div><h3>Top sources</h3><p class="sub">By ads stored</p></div><a class="vl" href="{{ route('sources.index') }}">View all &rsaquo;</a></div>
    @php $topMax = max(1, (int) ($topSources->first()?->raw_posts_count ?? 1)); @endphp
    @forelse ($topSources as $i => $source)
      <div class="rm"><div class="h"><a class="name" href="{{ route('sources.show', $source) }}">{{ $source->name }}</a><span>{{ $source->collection_method }} &middot; {{ $source->last_collection_at?->diffForHumans() ?? 'never collected' }}</span><em>{{ number_format($source->raw_posts_count) }}</em></div>
        <div class="bar"><i class="{{ $i === 0 ? 'hi' : '' }}" style="width:{{ max(3, round($source->raw_posts_count / $topMax * 100)) }}%"></i></div></div>
    @empty
      <p class="empty">No ads stored yet.</p>
    @endforelse
  </div>
  <div class="card">
    <div class="hd"><div><h3>Recent runs</h3><p class="sub">Latest collection attempts</p></div></div>
    @forelse ($recentRuns as $run)
      <div class="act"><div class="ic {{ $run->status === 'failed' ? 'bad' : ($run->status === 'partial' ? 'warn' : 'ok') }}"><svg class="i"><use href="#{{ $run->status === 'failed' ? 'x' : 'chk' }}"/></svg></div>
        <div><b>{{ $run->source?->name ?? 'Removed source' }}</b> &middot; {{ $run->status }}<br>
          <small>{{ $run->items_new }} new of {{ $run->items_found }} found &middot; {{ $run->started_at->diffForHumans() }}</small></div></div>
    @empty
      <p class="empty">No runs yet.</p>
    @endforelse
    <a class="link" href="{{ route('runs.index') }}">View all runs</a>
  </div>
</section>

<section class="card">
  <div class="hd"><div><h3>Needs your attention</h3><p class="sub">Items waiting on a decision</p></div>
    @if ($pending + $failing > 0)<span class="tag">{{ $pending + $failing }} open</span>@endif</div>
  <div class="g3">
    <div class="pa warn"><span class="n">{{ $pending }}</span><h4>Access decisions</h4><p>Sources whose terms you have not yet reviewed.</p><a href="{{ route('sources.index', ['access' => 'pending']) }}">Review now</a></div>
    <div class="pa bad"><span class="n">{{ $failing }}</span><h4>Failing sources</h4><p>Their last run failed. They back off and switch off by themselves.</p><a href="{{ route('sources.index', ['failing' => 1]) }}">View sources</a></div>
    <div class="pa"><span class="n">{{ $noCollector }}</span><h4>No collector yet</h4><p>Registered, but nothing is written to collect from them.</p><a href="{{ route('sources.index') }}">See sources</a></div>
  </div>
</section>
@endsection
