@extends('layouts.app')
@section('title', 'Possible duplicates')
@section('heading', 'Possible duplicates')
@section('subheading', 'Ads that look like the same vacancy but were not certain enough to merge by themselves')
@section('content')
@php
    $parts = ['employer' => 'Employer', 'title' => 'Title', 'location' => 'Place', 'deadline' => 'Closing date', 'posted' => 'Posted', 'url' => 'Application link', 'text' => 'Ad text'];
    $weights = config('job_intel.duplicates.weights');
@endphp

@if ($pairs->isEmpty())
  <section class="card"><p class="empty">Nothing is waiting for a decision. Ads that clearly match are merged automatically (scores of {{ config('job_intel.duplicates.auto') }} and above); pairs from {{ config('job_intel.duplicates.possible') }} up to that appear here.</p></section>
@endif

@foreach ($pairs as $pair)
  @php $a = $jobs[$pair->job_a_id] ?? null; $b = $jobs[$pair->job_b_id] ?? null; @endphp
  @continue(! $a || ! $b)
  <section class="card">
    <div class="hd">
      <div><h3>Score {{ $pair->score }} <span class="pill warn">possible</span></h3>
        <p class="sub">Merged automatically from {{ config('job_intel.duplicates.auto') }}. Different vacancies below {{ config('job_intel.duplicates.possible') }}.</p></div>
    </div>

    <div class="g3" style="grid-template-columns:1fr 1fr">
      @foreach ([$a, $b] as $j)
        <div class="card" style="box-shadow:none">
          <b><a class="name" href="{{ route('jobs.show', $j) }}">{{ $j->title }}</a></b>
          <dl class="kv" style="grid-template-columns:110px 1fr;gap:4px 12px;margin-top:10px;font-size:13px">
            <dt>Employer</dt><dd>{{ $j->employer_text ?? '-' }}</dd>
            <dt>Place</dt><dd>{{ $j->location_text ?? '-' }}</dd>
            <dt>Closes</dt><dd>{{ $j->closing_date?->format('j M Y') ?? 'not given' }}</dd>
            <dt>Posted</dt><dd>{{ $j->match_date?->format('j M Y') ?? '-' }}</dd>
            <dt>Source</dt><dd>{{ $j->rawPost?->source?->name }} <span class="mut">{{ \App\Models\Source::SOURCE_TYPES[$j->rawPost?->source?->source_type] ?? '' }}</span></dd>
            <dt>Status</dt><dd>@include('partials.job-status', ['status' => $j->status])</dd>
          </dl>
        </div>
      @endforeach
    </div>

    <div class="tw" style="margin-top:14px">
      <table class="tbl">
        <thead><tr>@foreach ($parts as $key => $label)<th>{{ $label }}</th>@endforeach</tr></thead>
        <tbody><tr>@foreach ($parts as $key => $label)<td class="nowrap">{{ $pair->breakdown['parts'][$key] ?? 0 }} <span class="mut">/ {{ $weights[$key] }}</span></td>@endforeach</tr></tbody>
      </table>
    </div>
    @foreach (($pair->breakdown['notes'] ?? []) as $note)<p class="sub" style="margin:6px 0 0">&bull; {{ $note }}</p>@endforeach

    <form method="POST" action="{{ route('duplicates.confirm', $pair->id) }}" class="btns" style="margin-top:14px">
      @csrf
      <span class="mut">Same vacancy. Main ad:</span>
      <label class="check"><input type="radio" name="main" value="{{ $a->id }}" @checked($pair->suggested === $a->id)> left</label>
      <label class="check"><input type="radio" name="main" value="{{ $b->id }}" @checked($pair->suggested === $b->id)> right</label>
      <button class="btn primary sm" type="submit">Same vacancy</button>
      <button class="btn sm" type="submit" formaction="{{ route('duplicates.reject', $pair->id) }}" formnovalidate>Different vacancies</button>
    </form>
    <p class="sub" style="margin:6px 0 0">The suggested main ad is the employer's own site first, then government, then job boards, then the oldest.</p>
  </section>
@endforeach

@if ($auto->isNotEmpty())
  <section class="card">
    <div class="hd"><div><h3>Matched automatically</h3><p class="sub">The latest merges, so you can check them. Open the copy to undo one.</p></div></div>
    @foreach ($auto as $pair)
      @php $a = $jobs[$pair->job_a_id] ?? null; $b = $jobs[$pair->job_b_id] ?? null; @endphp
      @continue(! $a || ! $b)
      <div class="act"><div class="ic ok"><svg class="i"><use href="#chk"/></svg></div>
        <div><a class="name" href="{{ route('jobs.show', $a) }}">{{ $a->title }}</a> <span class="mut">on {{ $a->rawPost?->source?->name }}</span> and
          <a class="name" href="{{ route('jobs.show', $b) }}">{{ $b->title }}</a> <span class="mut">on {{ $b->rawPost?->source?->name }}</span><br>
          <small>Score {{ $pair->score }} &middot; {{ ['exact_url' => 'same application link', 'exact_text' => 'same text, same employer', 'score' => 'similarity'][$pair->reason] ?? $pair->reason }}</small></div></div>
    @endforeach
  </section>
@endif
@endsection
