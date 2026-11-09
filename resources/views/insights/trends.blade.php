@extends('layouts.app')
@section('title', 'Trends')
@section('heading', 'Changes over time')
@section('subheading', 'How often a qualification is asked for, month by month')
@section('content')
@php
    $pct = fn (int $n, int $of) => $of > 0 ? round($n / $of * 100) : 0;
    $maxVacancies = max(1, (int) $months->max('vacancies'));
@endphp

@include('partials.advertised-demand-note')

<section class="card">
  @include('insights.partials.tabs', ['active' => 'trends'])

  <form class="filters" method="GET" action="{{ route('insights.trends') }}">
    <select name="subject" aria-label="What to follow">
      <optgroup label="Qualification">
        @foreach ($qualifications as $q)<option value="qualification:{{ $q->id }}" @selected($filters['subject'] === 'qualification:'.$q->id)>{{ $q->name }}</option>@endforeach
      </optgroup>
      <optgroup label="Education level">
        @foreach ($levels as $key => $label)<option value="level:{{ $key }}" @selected($filters['subject'] === 'level:'.$key)>{{ $label }}</option>@endforeach
      </optgroup>
    </select>
    <select name="province" aria-label="Province"><option value="">All of Zambia</option>
      @foreach ($provinces as $id => $pname)<option value="{{ $id }}" @selected((string) $filters['province'] === (string) $id)>{{ $pname }}</option>@endforeach
    </select>
    <button class="btn" type="submit">Apply</button>
    <a class="btn" href="{{ route('insights.export', array_filter(['report' => 'trends', 'subject' => $filters['subject'], 'province' => $filters['province']])) }}">Download CSV</a>
  </form>

  <p class="sub" style="margin:12px 0 0">
    Each month is the ad's posting date (the day it was first collected when the ad gives none), and includes open and closed vacancies.
    A share is shown only for a month with at least {{ $minMonth }} vacancies read. A rise or fall can mean employers changed what they ask for,
    or only that different sources or employers were collected that month, so check the flags. This page does not say a qualification is "growing" or "falling".
  </p>
</section>

@if ($months->isEmpty() || ! $name)
  <section class="card"><p class="empty">Nothing to follow yet. No vacancy has been read, or no qualification has been found.</p></section>
@else
<section class="card">
  <div class="hd"><div><h3>{{ $name }}</h3><p class="sub">Share of the vacancies read that month which ask for it</p></div></div>
  <div class="tw">
    <table class="tbl">
      <thead><tr><th>Month</th><th>Vacancies read</th><th>Ask for it</th><th>Share</th><th>Notes</th></tr></thead>
      <tbody>
      @foreach ($months as $m)
        <tr>
          <td class="nowrap"><b>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $m->month)->format('M Y') }}</b></td>
          <td class="nowrap">{{ $m->vacancies }}</td>
          <td class="nowrap">{{ $m->vacancies > 0 ? $m->asking : '—' }}</td>
          <td style="min-width:140px">
            @if ($m->share !== null)
              <div class="h" style="display:flex;gap:8px;align-items:center"><span style="min-width:2.6em">{{ $m->share }}%</span>
                <div class="bar" style="flex:1"><i style="width:{{ max(2, $m->share) }}%"></i></div></div>
            @else
              <small>{{ $m->vacancies === 0 ? 'no vacancies' : 'too few to read' }}</small>
            @endif
          </td>
          <td>
            @if ($m->thin && $m->vacancies > 0)<span class="pill mut">Under {{ $minMonth }}</span>@endif
            @if ($m->mix_changed)<span class="pill warn" title="The sources the month's vacancies came from shifted by {{ $m->mix_shift }} points from the month before">Different source mix</span>@endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
  <p class="sub" style="margin-top:10px">"Different source mix": the sources that month's vacancies came from shifted by {{ $shift }} points or more (half the total change in each source's share) from the previous month with vacancies, so the month may not be comparable with the one before.</p>
</section>
@endif
@endsection
