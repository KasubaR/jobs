@extends('layouts.app')
@section('title', 'Supply and demand')
@section('heading', 'Education opportunities and employer demand')
@section('subheading', 'Providers recorded in the directory, beside what advertisements ask for')
@section('content')
@php
    $rows = $report['rows'];
    $kind = fn ($k) => ucfirst(str_replace('_', ' ', $k));
    $exportQuery = array_filter(['scope' => $filters['scope'] === 'all' ? null : $filters['scope'], 'province' => $filters['province']]);
@endphp

@include('partials.advertised-demand-note')

<section class="card">
  @include('insights.partials.tabs', ['active' => 'supply'])

  <form class="filters" method="GET" action="{{ route('insights.supply') }}">
    <select name="scope" aria-label="Which vacancies">
      <option value="all" @selected($filters['scope'] === 'all')>All vacancies (open and closed)</option>
      <option value="open" @selected($filters['scope'] === 'open')>Open now only</option>
    </select>
    <select name="province" aria-label="Province"><option value="">All of Zambia</option>
      @foreach ($provinces as $id => $pname)<option value="{{ $id }}" @selected((string) $filters['province'] === (string) $id)>{{ $pname }}</option>@endforeach
    </select>
    <button class="btn" type="submit">Apply</button>
    <a class="btn" href="{{ route('insights.export', ['report' => 'supply'] + $exportQuery) }}">Download CSV</a>
  </form>

  <div class="alert warn" style="margin:14px 0 0">
    <b>The provider side is only what has been entered into this directory.</b>
    "No provider recorded" means none is recorded here, not that none exists, and a qualification asked for in few vacancies may still be widely taught.
    Use this as a list of places to look, not as a finding about the education system. Vacancies are not divided by providers, because that would treat both sides as complete.
  </div>

  <div class="g4 st" style="margin-top:14px">
    <div class="kpi"><span class="kl">Programmes recorded</span><b class="kv">{{ number_format($report['programmes']) }}</b>
      <div class="kc"><small>{{ $report['providers'] }} {{ Str::plural('provider', $report['providers']) }}; {{ $report['verified_programmes'] }} with a verified source</small></div></div>
    <div class="kpi"><span class="kl">Qualifications asked for</span><b class="kv">{{ number_format($report['asked']) }}</b>
      <div class="kc"><small>In the {{ number_format($analysed) }} vacancies read</small></div></div>
    <div class="kpi"><span class="kl">With a provider recorded</span><b class="kv">{{ number_format($report['asked_with_provider']) }}</b>
      <div class="kc"><small>of {{ number_format($report['asked']) }} asked for</small></div></div>
  </div>
</section>

@if ($rows->isEmpty())
  <section class="card"><p class="empty">Nothing to compare yet. No vacancy has been read, and no programme is recorded. Load providers with <code>php artisan directory:import programmes FILE</code>.</p></section>
@else
<section class="card">
  <div class="tw">
    <table class="tbl">
      <thead><tr><th>Qualification</th><th>Vacancies asking</th><th>Providers recorded</th><th>Programmes</th><th>Note</th></tr></thead>
      <tbody>
      @foreach ($rows as $r)
        <tr>
          <td><a href="{{ route('directory.show', $r->id) }}"><b>{{ $r->name }}</b></a><br><small>{{ $kind($r->kind) }}</small></td>
          <td class="nowrap">{{ $r->vacancies }}@if ($r->share !== null) <small>({{ $r->share }}%)</small>@elseif ($r->vacancies > 0 && ! $r->reliable) <small>(too few for a share)</small>@endif</td>
          <td class="nowrap">{{ $r->providers }}</td>
          <td class="nowrap">{{ $r->programmes }}@if ($r->verified) <small>({{ $r->verified }} verified)</small>@endif</td>
          <td>
            @if ($r->status === 'none_recorded')<span class="pill warn">No provider recorded</span>
            @elseif ($r->status === 'not_asked')<span class="pill mut">Offered; not asked for in vacancies read</span>
            @else<span class="pill">Provider recorded</span>@endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</section>
@endif
@endsection
