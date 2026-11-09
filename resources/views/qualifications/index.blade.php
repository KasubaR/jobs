@extends('layouts.app')
@section('title', 'Qualifications')
@section('heading', 'Qualifications')
@section('subheading', 'What employers ask for, counting each vacancy once')
@section('content')
@php
    $pct = fn (int $n, int $of) => $of > 0 ? round($n / $of * 100) : 0;
@endphp

<section class="card">
  <form class="filters" method="GET" action="{{ route('qualifications.index') }}">
    <select name="scope" aria-label="Which vacancies">
      <option value="all" @selected($filters['scope'] === 'all')>All vacancies (open and closed)</option>
      <option value="open" @selected($filters['scope'] === 'open')>Open now only</option>
    </select>
    <select name="province" aria-label="Province"><option value="">All of Zambia</option>
      @foreach ($provinces as $id => $name)<option value="{{ $id }}" @selected((string) $filters['province'] === (string) $id)>{{ $name }}</option>@endforeach
    </select>
    <button class="btn" type="submit">Apply</button>
    @if ($filters['scope'] !== 'all' || $filters['province'])<a class="btn" href="{{ route('qualifications.index') }}">Clear</a>@endif
  </form>

  <div class="g4 st">
    <div class="kpi"><span class="kl">Vacancies counted</span><b class="kv">{{ number_format($total) }}</b>
      <div class="kc"><small>Copies of the same vacancy and rejected ads are left out</small></div></div>
    <div class="kpi"><span class="kl">State a qualification</span><b class="kv">{{ number_format($read) }}</b>
      <div class="kc"><small>{{ $pct($read, $total) }}% of the vacancies counted</small></div>
      <div class="tr"><i style="width:{{ $pct($read, $total) }}%"></i></div></div>
    <div class="kpi"><span class="kl">Text found, not recognised</span><b class="kv">{{ number_format($notRecognised) }}</b>
      <div class="kc"><small>@if ($notRecognised)<a href="{{ route('values.index') }}">Map them</a> so they count @else Nothing waiting @endif</small></div></div>
    <div class="kpi"><span class="kl">Nothing read</span><b class="kv">{{ number_format($nothing) }}</b>
      <div class="kc"><small>No qualification found in the ad yet</small></div>
      <div class="tr amber"><i style="width:{{ $pct($nothing, $total) }}%"></i></div></div>
  </div>

  @if ($total > 0 && $read < $total)
    <div class="alert warn" style="margin:16px 0 0">
      <b>{{ $total - $read }} of {{ $total }} {{ Str::plural('vacancy', $total) }} {{ $total - $read === 1 ? 'has' : 'have' }} no qualification counted below.</b>
      The reader only picks up a qualification written on a labelled line ("Qualification: …") or as a requirements bullet that starts with it.
      A qualification written inside a sentence is not read yet. Reading the whole ad is the next build step, and it will raise these numbers.
      Every figure below is a share of the {{ $read }} that were read, not of all {{ $total }}.
    </div>
  @endif
</section>

@if ($total === 0)
  <section class="card"><p class="empty">No vacancies yet. They appear once processing is switched on and ads have been collected.</p></section>
@else
<section class="split2" style="grid-template-columns:1fr 1fr">
  <div class="card">
    <div class="hd"><div><h3>Education level</h3><p class="sub">The level asked for, of {{ $read }} that state one</p></div></div>
    @forelse ($levels as $i => $row)
      <div class="rm"><div class="h"><b>{{ $row['name'] }}</b><span>{{ $pct($row['n'], $read) }}%</span><em>{{ number_format($row['n']) }}</em></div>
        <div class="bar"><i class="{{ $i === 0 ? 'hi' : '' }}" style="width:{{ max(3, $pct($row['n'], (int) $levels->max('n'))) }}%"></i></div></div>
    @empty
      <p class="empty">No education level was read from any ad yet.</p>
    @endforelse
  </div>

  <div class="card">
    <div class="hd"><div><h3>Most requested qualifications</h3><p class="sub">The qualification as standardized, with its field</p></div></div>
    @forelse ($labels as $i => $row)
      <div class="rm"><div class="h"><b>{{ $row['name'] }}</b><span>{{ $pct($row['n'], $read) }}%</span><em>{{ number_format($row['n']) }}</em></div>
        <div class="bar"><i class="{{ $i === 0 ? 'hi' : '' }}" style="width:{{ max(3, $pct($row['n'], (int) $labels->max('n'))) }}%"></i></div></div>
    @empty
      <p class="empty">Nothing to rank yet.</p>
    @endforelse
  </div>
</section>

<section class="split2" style="grid-template-columns:1fr 1fr">
  <div class="card">
    <div class="hd"><div><h3>Fields of study</h3><p class="sub">Where the ad names one</p></div></div>
    @forelse ($fields as $i => $row)
      <div class="rm"><div class="h"><b>{{ $row['name'] }}</b><span>{{ $pct($row['n'], $read) }}%</span><em>{{ number_format($row['n']) }}</em></div>
        <div class="bar"><i class="{{ $i === 0 ? 'hi' : '' }}" style="width:{{ max(3, $pct($row['n'], (int) $fields->max('n'))) }}%"></i></div></div>
    @empty
      <p class="empty">No field of study was read yet.</p>
    @endforelse
  </div>

  <div class="card">
    <div class="hd"><div><h3>Experience asked for</h3><p class="sub">{{ number_format($experienceRead) }} {{ Str::plural('vacancy', $experienceRead) }} state a length of experience</p></div></div>
    @forelse ($experience as $i => $row)
      <div class="rm"><div class="h"><b>{{ $row['name'] }}</b><span>{{ $pct($row['n'], $experienceRead) }}%</span><em>{{ number_format($row['n']) }}</em></div>
        <div class="bar"><i class="{{ $i === 0 ? 'hi' : '' }}" style="width:{{ max(3, $pct($row['n'], (int) $experience->max('n'))) }}%"></i></div></div>
    @empty
      <p class="empty">No length of experience was read yet.</p>
    @endforelse
  </div>
</section>

<section class="card">
  <div class="hd"><div><h3>Skills</h3><p class="sub">Technical and soft skills employers ask for</p></div></div>
  <p class="empty" style="text-align:left">Skills are not read yet. They come from the next build step, which reads the whole ad and grades each skill and qualification as required, preferred, an added advantage, or only mentioned, so that "most sought after" means what employers actually require.</p>
</section>
@endif
@endsection
