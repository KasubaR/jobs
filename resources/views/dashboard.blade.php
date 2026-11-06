@extends('layouts.app')
@section('title', 'Dashboard')
@section('heading', 'Dashboard')
@section('subheading', 'How collection is going')
@section('content')
<section class="banner">
  <div class="bt">
    <div><h2>Welcome back</h2><span class="date">{{ now()->format('l, F j, Y') }}</span><p>Here is how your sources and collected ads are doing.</p></div>
    <div class="btns"><a class="btn" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:#fff" href="{{ route('sources.create') }}"><svg class="i"><use href="#plus"/></svg>Add source</a></div>
  </div>
  <div class="g4">
    <div class="glass"><span><svg class="i"><use href="#globe"/></svg>Active sources</span><b>{{ $activeSources }}</b><small>of {{ $totalSources }} registered</small></div>
    <div class="glass"><span><svg class="i"><use href="#box"/></svg>Ads collected</span><b>{{ number_format($adsTotal) }}</b><small>{{ $adsToday }} in the last 24 hours</small></div>
    <div class="glass"><span><svg class="i"><use href="#shield"/></svg>Approved sources</span><b>{{ $approved }}</b><small>{{ $pending }} awaiting a decision</small></div>
    <div class="glass"><span><svg class="i"><use href="#x"/></svg>Failing sources</span><b>{{ $failing }}</b><small>{{ $failing ? 'need attention' : 'all healthy' }}</small></div>
  </div>
</section>

<section class="g4 st">
  <div class="card stat" style="--c:#1565a8"><div class="top"><div class="ic"><svg class="i"><use href="#globe"/></svg></div></div><b>{{ $totalSources }}</b><div class="l">Sources</div><div class="s">Registered websites</div>
    <div class="pr"><span>Approved</span><b>{{ $approved }}/{{ $totalSources }}</b></div><div class="tr"><i style="width:{{ $totalSources ? round($approved / $totalSources * 100) : 0 }}%"></i></div></div>
  <div class="card stat" style="--c:#12a05c"><div class="top"><div class="ic"><svg class="i"><use href="#up"/></svg></div></div><b>{{ $runCount }}</b><div class="l">Runs this week</div><div class="s">Last 7 days</div>
    <div class="pr"><span>Completed</span><b>{{ $runOk }}/{{ $runCount }}</b></div><div class="tr"><i style="width:{{ $runCount ? round($runOk / $runCount * 100) : 0 }}%"></i></div></div>
  <div class="card stat" style="--c:#f97316"><div class="top"><div class="ic"><svg class="i"><use href="#box"/></svg></div></div><b>{{ number_format($adsTotal) }}</b><div class="l">Ads stored</div><div class="s">All sources</div>
    <div class="pr"><span>With raw text</span><b>{{ number_format($rawKept) }}</b></div><div class="tr"><i style="width:{{ $adsTotal ? round($rawKept / $adsTotal * 100) : 0 }}%"></i></div></div>
  <div class="card stat" style="--c:#8b2ff0"><div class="top"><div class="ic"><svg class="i"><use href="#gear"/></svg></div></div><b>{{ $noCollector }}</b><div class="l">Without a collector</div><div class="s">Cannot be collected yet</div>
    <div class="pr"><span>Share of sources</span><b>{{ $totalSources ? round($noCollector / $totalSources * 100) : 0 }}%</b></div><div class="tr"><i style="width:{{ $totalSources ? round($noCollector / $totalSources * 100) : 0 }}%"></i></div></div>
</section>

<section class="split">
  <div class="card">
    <div class="hd"><div><h3>Collection activity</h3><p class="sub">New ads found each day, last 7 days</p></div></div>
    @foreach ($days as $day)
      <div class="rm"><div class="h"><b>{{ $day['label'] }}</b><span>&bull; {{ $day['runs'] }} {{ Str::plural('run', $day['runs']) }}</span><em>{{ $day['ads'] }} new</em></div>
        <div class="bar"><i style="width:{{ $day['ads'] ? max(4, round($day['ads'] / $maxAds * 100)) : 0 }}%"></i></div></div>
    @endforeach
  </div>
  <div class="card">
    <div class="hd"><div><h3>Recent runs</h3><p class="sub">Latest collection attempts</p></div></div>
    @forelse ($recentRuns as $run)
      <div class="act"><div class="ic" style="--c:{{ $run->status === 'failed' ? '#dc2626' : ($run->status === 'partial' ? '#f59e0b' : '#12a05c') }}"><svg class="i"><use href="#{{ $run->status === 'failed' ? 'x' : 'chk' }}"/></svg></div>
        <div><b>{{ $run->source?->name ?? 'Removed source' }}</b> &middot; {{ $run->status }}<br>
          <small>{{ $run->items_new }} new of {{ $run->items_found }} found &middot; {{ $run->started_at->diffForHumans() }}</small></div></div>
    @empty
      <p class="empty">No runs yet.</p>
    @endforelse
    <a class="link" href="{{ route('runs.index') }}">View all runs</a>
  </div>
</section>

<section class="split2">
  <div class="card">
    <div class="hd"><div><h3>Quick actions</h3><p class="sub">Common tasks</p></div></div>
    <div class="qa">
      <a style="--g:linear-gradient(135deg,#1a6db5,#0d4a86)" href="{{ route('sources.create') }}"><svg class="i"><use href="#plus"/></svg>Add source<small>Register a website</small></a>
      <a style="--g:linear-gradient(135deg,#12a05c,#0a7f6f)" href="{{ route('sources.index', ['access' => 'pending']) }}"><svg class="i"><use href="#shield"/></svg>Decide access<small>{{ $pending }} pending</small></a>
      <a style="--g:linear-gradient(135deg,#fb923c,#ea580c)" href="{{ route('sources.index', ['failing' => 1]) }}"><svg class="i"><use href="#x"/></svg>Failing sources<small>{{ $failing }} to check</small></a>
      <a style="--g:linear-gradient(135deg,#a855f7,#7c1fe0)" href="{{ route('runs.index') }}"><svg class="i"><use href="#clock"/></svg>Collection runs<small>History and errors</small></a>
    </div>
  </div>
  <div class="card">
    <div class="hd"><div><h3>Top sources</h3><p class="sub">By ads stored</p></div><a class="vl" href="{{ route('sources.index') }}">View all &rsaquo;</a></div>
    @php $colours = ['#eab308', '#94a3b8', '#f97316']; @endphp
    @forelse ($topSources as $i => $source)
      <div class="sel" style="--c:{{ $colours[$i] ?? '#94a3b8' }}"><div class="rk">{{ strtoupper(substr($source->name, 0, 2)) }}<u>{{ $i + 1 }}</u></div>
        <div class="g"><b><a class="name" style="text-decoration:none;color:inherit" href="{{ route('sources.show', $source) }}">{{ $source->name }}</a></b><small>{{ $source->collection_method }} &middot; {{ $source->last_collection_at?->diffForHumans() ?? 'never collected' }}</small></div>
        <div class="r"><b>{{ number_format($source->raw_posts_count) }}</b><small>ads</small></div></div>
    @empty
      <p class="empty">No ads stored yet.</p>
    @endforelse
  </div>
</section>

<section class="card">
  <div class="hd"><div><h3>Needs your attention</h3><p class="sub">Items waiting on a decision</p></div>
    @if ($pending + $failing > 0)<span class="tag">{{ $pending + $failing }} open</span>@endif</div>
  <div class="g3">
    <div class="pa" style="--c:#f97316"><div class="ic"><svg class="i"><use href="#shield"/></svg></div><span class="n">{{ $pending }}</span><h4>Access decisions</h4><p>Sources whose terms you have not yet reviewed.</p><a href="{{ route('sources.index', ['access' => 'pending']) }}">Review now</a></div>
    <div class="pa" style="--c:#dc2626"><div class="ic"><svg class="i"><use href="#x"/></svg></div><span class="n">{{ $failing }}</span><h4>Failing sources</h4><p>Their last run failed. They back off and switch off by themselves.</p><a href="{{ route('sources.index', ['failing' => 1]) }}">View sources</a></div>
    <div class="pa" style="--c:#1565a8"><div class="ic"><svg class="i"><use href="#gear"/></svg></div><span class="n">{{ $noCollector }}</span><h4>No collector yet</h4><p>Registered, but nothing is written to collect from them.</p><a href="{{ route('sources.index') }}">See sources</a></div>
  </div>
</section>
@endsection
