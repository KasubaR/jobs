@extends('layouts.app')
@php $isOcc = $dimension === 'occupation'; $label = $isOcc ? 'occupation' : 'industry'; @endphp
@section('title', 'Qualifications by '.$label)
@section('heading', 'Qualifications by '.$label)
@section('subheading', 'What vacancies in each '.$label.' ask for')
@section('content')
@php
    $pct = fn (int $n, int $of) => $of > 0 ? round($n / $of * 100) : 0;
    $kind = fn ($k) => ucfirst(str_replace('_', ' ', $k));
    $query = fn (array $extra = []) => array_filter(array_merge(['scope' => $filters['scope'] === 'all' ? null : $filters['scope'], 'province' => $filters['province'], 'level' => $filters['level'] === 'submajor' ? null : $filters['level']], $extra), fn ($v) => $v !== null && $v !== '');
@endphp

@include('partials.advertised-demand-note')

<section class="card">
  <div class="btns" style="margin-bottom:12px">
    <a class="btn sm" href="{{ route('insights.index', $query()) }}">Overview</a>
    <a class="btn sm {{ $isOcc ? 'primary' : '' }}" href="{{ route('insights.group', ['dimension' => 'occupation'] + $query()) }}">By occupation</a>
    <a class="btn sm {{ ! $isOcc ? 'primary' : '' }}" href="{{ route('insights.group', ['dimension' => 'industry'] + $query()) }}">By industry</a>
    <a class="btn sm" href="{{ route('insights.trends') }}">Over time</a>
  </div>

  <form class="filters" method="GET" action="{{ route('insights.group', $dimension) }}">
    <select name="scope" aria-label="Which vacancies">
      <option value="all" @selected($filters['scope'] === 'all')>All vacancies (open and closed)</option>
      <option value="open" @selected($filters['scope'] === 'open')>Open now only</option>
    </select>
    <select name="province" aria-label="Province"><option value="">All of Zambia</option>
      @foreach ($provinces as $id => $name)<option value="{{ $id }}" @selected((string) $filters['province'] === (string) $id)>{{ $name }}</option>@endforeach
    </select>
    @if ($isOcc)
      <select name="level" aria-label="Occupation detail">
        <option value="submajor" @selected($filters['level'] === 'submajor')>Occupation groups (broader)</option>
        <option value="unit" @selected($filters['level'] === 'unit')>Specific occupations</option>
      </select>
    @endif
    <button class="btn" type="submit">Apply</button>
  </form>

  <p class="sub" style="margin:12px 0 0">
    {{ $label === 'industry' ? 'An industry' : 'An occupation' }} is known for {{ number_format($groups['known']) }} of {{ number_format($groups['analysed']) }} vacancies read.
    The rest are not in any group below{{ $isOcc ? ', because the job title did not match an occupation' : ', because the employer has no industry yet' }}{{ ! $isOcc ? ' (set it on the Employers page)' : '' }}.
    A share is only shown for a group of at least {{ $minSample }} vacancies.
  </p>
</section>

@if ($groups['groups']->isEmpty())
  <section class="card"><p class="empty">No vacancy has a known {{ $label }} yet.</p></section>
@else
<section class="split2" style="grid-template-columns:1fr 2fr">
  <div class="card">
    <div class="hd"><div><h3>{{ ucfirst($label) }}</h3><p class="sub">Vacancies read, most first</p></div></div>
    @foreach ($groups['groups'] as $g)
      <div class="rm"><div class="h">
        <a href="{{ route('insights.group', ['dimension' => $dimension] + $query(['group' => $g->key])) }}"><b @style(['text-decoration:underline' => $filters['group'] === (string) $g->key])>{{ $g->name }}</b></a>
        <em>{{ $g->vacancies }}</em></div>
        @unless ($g->reliable)<small>too few to read</small>@endunless</div>
    @endforeach
  </div>

  <div class="card">
    @if (! $selected)
      <p class="empty">Choose {{ $isOcc ? 'an occupation' : 'an industry' }} to see what its vacancies ask for.</p>
    @else
      <div class="hd"><div><h3>{{ $selected->name }}</h3>
        <p class="sub">{{ $detail['vacancies'] }} {{ Str::plural('vacancy', $detail['vacancies']) }} read. The share beside each is of these; "overall" is the same qualification across all vacancies read.</p></div></div>

      @if (! $detail['reliable'])
        <p class="empty">Only {{ $detail['vacancies'] }} {{ Str::plural('vacancy', $detail['vacancies']) }} here, fewer than {{ $minSample }}. Counts are shown but not shares.</p>
      @endif

      @forelse ($detail['qualifications'] as $i => $row)
        <div class="rm"><div class="h"><a href="{{ route('directory.show', $row->id) }}"><b>{{ $row->name }}</b></a>
          <span>{{ $row->share !== null ? $row->share.'%' : 'too few' }}</span><em>{{ $row->vacancies }}</em></div>
          <div class="bar"><i class="{{ $i === 0 && $row->reliable ? 'hi' : '' }}" style="width:{{ max(3, $pct($row->vacancies, (int) $detail['qualifications']->max('vacancies'))) }}%;{{ $row->reliable ? '' : 'opacity:.4' }}"></i></div>
          <small>{{ $kind($row->kind) }} · {{ $row->required }} of {{ $row->vacancies }} require it @if ($row->share !== null && $row->overall_share !== null) · overall {{ $row->overall_share }}%@endif</small></div>
      @empty
        <p class="empty">None of these vacancies asks for a qualification we could place.</p>
      @endforelse

      @if ($detail['levels']->isNotEmpty())
        <h4 style="margin:18px 0 6px">Education level asked for</h4>
        @foreach ($detail['levels'] as $row)
          <div class="rm"><div class="h"><b>{{ $row->name }}</b><span>{{ $row->share !== null ? $row->share.'%' : 'too few' }}</span><em>{{ $row->vacancies }}</em></div>
            <div class="bar"><i style="width:{{ max(3, $pct($row->vacancies, (int) $detail['levels']->max('vacancies'))) }}%"></i></div></div>
        @endforeach
      @endif
    @endif
  </div>
</section>
@endif
@endsection
