@extends('layouts.app')
@section('title', 'Insights')
@section('heading', 'Insights')
@section('subheading', 'What advertisements ask for, counting each vacancy once')
@section('content')
@php
    $analysed = $counts['analysed'];
    $pct = fn (int $n, int $of) => $of > 0 ? round($n / $of * 100) : 0;
    $kind = fn ($k) => ucfirst(str_replace('_', ' ', $k));
@endphp

@include('partials.advertised-demand-note')

<section class="card">
  <div class="btns" style="margin-bottom:12px">
    <a class="btn sm primary" href="{{ route('insights.index') }}">Overview</a>
    <a class="btn sm" href="{{ route('insights.group', 'occupation') }}">By occupation</a>
    <a class="btn sm" href="{{ route('insights.group', 'industry') }}">By industry</a>
    <a class="btn sm" href="{{ route('insights.trends') }}">Over time</a>
  </div>

  <form class="filters" method="GET" action="{{ route('insights.index') }}">
    <select name="scope" aria-label="Which vacancies">
      <option value="all" @selected($filters['scope'] === 'all')>All vacancies (open and closed)</option>
      <option value="open" @selected($filters['scope'] === 'open')>Open now only</option>
    </select>
    <select name="province" aria-label="Province"><option value="">All of Zambia</option>
      @foreach ($provinces as $id => $name)<option value="{{ $id }}" @selected((string) $filters['province'] === (string) $id)>{{ $name }}</option>@endforeach
    </select>
    <button class="btn" type="submit">Apply</button>
    @if ($filters['scope'] !== 'all' || $filters['province'])<a class="btn" href="{{ route('insights.index') }}">Clear</a>@endif
  </form>

  <div class="g4 st">
    <div class="kpi"><span class="kl">Vacancies collected</span><b class="kv">{{ number_format($counts['collected']) }}</b>
      <div class="kc"><small>Copies and rejected ads left out</small></div></div>
    <div class="kpi"><span class="kl">Read by the reader</span><b class="kv">{{ number_format($analysed) }}</b>
      <div class="kc"><small>{{ $pct($analysed, $counts['collected']) }}% of those collected. Every share below is of these.</small></div>
      <div class="tr"><i style="width:{{ $pct($analysed, $counts['collected']) }}%"></i></div></div>
    <div class="kpi"><span class="kl">Ask for a qualification</span><b class="kv">{{ number_format($counts['stating']) }}</b>
      <div class="kc"><small>{{ $pct($counts['stating'], $analysed) }}% of those read. The rest name none we could place.</small></div></div>
  </div>

  @if ($counts['collected'] > $analysed)
    <p class="sub" style="margin:14px 0 0">{{ $counts['collected'] - $analysed }} {{ Str::plural('vacancy', $counts['collected'] - $analysed) }} could not be read (the text was not kept, or reading failed). They are not counted as asking for nothing.</p>
  @endif
  <p class="sub" style="margin:8px 0 0">Only qualifications an ad requires or prefers are counted. A figure from fewer than {{ $minSample }} vacancies is shown as a count only, because it is too few to read as a pattern.</p>
</section>

@if ($analysed === 0)
  <section class="card"><p class="empty">No vacancy has been read yet, so there is nothing to measure. Run <code>processing:dispatch --outdated</code> to read stored ads.</p></section>
@else
<section class="split2" style="grid-template-columns:1fr 1fr">
  <div class="card">
    <div class="hd"><div><h3>Most requested qualifications</h3><p class="sub">Share of the {{ number_format($analysed) }} vacancies read</p></div></div>
    @forelse ($asked as $i => $row)
      <div class="rm"><div class="h"><a href="{{ route('directory.show', $row->id) }}"><b>{{ $row->name }}</b></a>
        <span>{{ $row->share !== null ? $row->share.'%' : 'too few' }}</span><em>{{ $row->vacancies }}</em></div>
        <div class="bar"><i class="{{ $i === 0 && $row->reliable ? 'hi' : '' }}" style="width:{{ max(3, $pct($row->vacancies, (int) $asked->max('vacancies'))) }}%;{{ $row->reliable ? '' : 'opacity:.4' }}"></i></div>
        <small>{{ $kind($row->kind) }} · {{ $row->required }} of {{ $row->vacancies }} require it</small></div>
    @empty
      <p class="empty">No qualification was read from any ad yet.</p>
    @endforelse
  </div>

  <div class="card">
    <div class="hd"><div><h3>Education level</h3><p class="sub">A vacancy asking for "a diploma or degree" counts under both</p></div></div>
    @forelse ($levels as $row)
      <div class="rm"><div class="h"><b>{{ $row->name }}</b><span>{{ $row->share !== null ? $row->share.'%' : 'too few' }}</span><em>{{ $row->vacancies }}</em></div>
        <div class="bar"><i style="width:{{ max(3, $pct($row->vacancies, (int) $levels->max('vacancies'))) }}%;{{ $row->reliable ? '' : 'opacity:.4' }}"></i></div></div>
    @empty
      <p class="empty">No education level was read yet.</p>
    @endforelse
  </div>
</section>

<section class="card">
  <div class="hd"><div><h3>Entry-level requirements</h3>
    <p class="sub">Vacancies whose title says intern, junior or graduate, or that ask for at most {{ \App\Analytics\AdvertisedDemand::ENTRY_MAX_YEARS }} {{ Str::plural('year', \App\Analytics\AdvertisedDemand::ENTRY_MAX_YEARS) }} of experience. An ad that says nothing about experience is not counted as entry-level.</p></div></div>
  @if ($entry['vacancies'] === 0)
    <p class="empty">No entry-level vacancy among those read.</p>
  @elseif (! $entry['reliable'])
    <p class="empty">Only {{ $entry['vacancies'] }} entry-level {{ Str::plural('vacancy', $entry['vacancies']) }} so far. Shares are shown from {{ $minSample }}, so they are not mistaken for a pattern.</p>
  @else
    <p class="sub">{{ $entry['vacancies'] }} entry-level vacancies, {{ $pct($entry['vacancies'], $analysed) }}% of those read.</p>
    <div class="split2" style="grid-template-columns:1fr 1fr">
      <div>
        <b>Level asked for</b>
        @foreach ($entry['levels'] as $row)
          <div class="rm"><div class="h"><b>{{ $row->name }}</b><span>{{ $row->share !== null ? $row->share.'%' : 'too few' }}</span><em>{{ $row->vacancies }}</em></div>
            <div class="bar"><i style="width:{{ max(3, $pct($row->vacancies, (int) $entry['levels']->max('vacancies'))) }}%;{{ $row->reliable ? '' : 'opacity:.4' }}"></i></div></div>
        @endforeach
      </div>
      <div>
        <b>Qualifications asked for</b>
        @forelse ($entry['qualifications'] as $row)
          <div class="rm"><div class="h"><a href="{{ route('directory.show', $row->id) }}"><b>{{ $row->name }}</b></a><span>{{ $row->share !== null ? $row->share.'%' : 'too few' }}</span><em>{{ $row->vacancies }}</em></div>
            <div class="bar"><i style="width:{{ max(3, $pct($row->vacancies, (int) $entry['qualifications']->max('vacancies'))) }}%;{{ $row->reliable ? '' : 'opacity:.4' }}"></i></div></div>
        @empty
          <p class="empty">None.</p>
        @endforelse
      </div>
    </div>
  @endif
</section>

<section class="card">
  <div class="hd"><div><h3>Professional qualifications asked for alongside a degree</h3>
    <p class="sub">Of {{ $pairs['degree_vacancies'] }} vacancies that ask for a degree or higher, {{ $pairs['with_professional'] }} also ask for a professional qualification.</p></div></div>
  @forelse ($pairs['qualifications'] as $row)
    <div class="rm"><div class="h"><a href="{{ route('directory.show', $row->id) }}"><b>{{ $row->name }}</b></a>
      <span>{{ $row->share !== null ? $row->share.'% with a degree' : 'too few' }}</span><em>{{ $row->vacancies }}</em></div>
      <div class="bar"><i style="width:{{ $row->share ?? max(3, $pct($row->with_degree, max(1, $row->vacancies))) }}%;{{ $row->reliable ? '' : 'opacity:.4' }}"></i></div>
      <small>{{ $row->with_degree }} of {{ $row->vacancies }} {{ Str::plural('vacancy', $row->vacancies) }} asking for it also ask for a degree or higher</small></div>
  @empty
    <p class="empty">No professional qualification was read from any ad yet.</p>
  @endforelse
</section>
@endif
@endsection
