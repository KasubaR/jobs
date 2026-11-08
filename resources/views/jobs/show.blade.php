@extends('layouts.app')
@section('title', $job->title)
@section('heading', 'Job')
@section('subheading', $job->title)
@section('content')
@php
    $src = $job->rawPost?->source;
    $sources = $job->field_sources ?? [];
    $how = ['structured' => 'published by the ad', 'rule' => 'read from the text', 'title' => 'from the title', 'text' => 'from the text'];
    $typeLabels = collect($job->employment_types ?? [])->map(fn ($t) => $types[$t] ?? $t)->implode(', ');
@endphp

<section class="card">
  <div class="hd">
    <div>
      <h3>{{ $job->title }}</h3>
      <p class="sub">@include('partials.job-status', ['status' => $job->status]) &nbsp; {{ $job->status_reason }}</p>
    </div>
    <div class="btns">
      <a class="btn" href="{{ $job->source_url }}" target="_blank" rel="noopener noreferrer">Open the ad</a>
      @if ($job->review_status === 'needs_review')
        <form method="POST" action="{{ route('jobs.approve', $job) }}">@csrf<button class="btn primary" type="submit">Mark as reviewed</button></form>
      @endif
    </div>
  </div>

  @if ($job->status === 'duplicate' && $job->duplicateOf)
    <div class="alert warn">This is a copy of <a href="{{ route('jobs.show', $job->duplicateOf) }}"><b>{{ $job->duplicateOf->title }}</b></a> ({{ $job->duplicateOf->rawPost?->source?->name }}).
      <form method="POST" action="{{ route('jobs.separate', $job) }}" style="display:inline">@csrf<button class="btn sm" type="submit">It is a different vacancy</button></form></div>
  @endif

  @if ($job->status !== 'duplicate')
    <form method="POST" action="{{ route('jobs.status', $job) }}" class="btns">
      @csrf
      <input type="text" name="reason" maxlength="255" placeholder="Reason (optional)" aria-label="Reason" style="min-width:16rem">
      <button class="btn primary sm" type="submit" name="status" value="published">Publish</button>
      <button class="btn danger sm" type="submit" name="status" value="rejected">Reject</button>
    </form>
  @endif
</section>

<section class="card">
  <div class="hd"><div><h3>What the ad said and what we made of it</h3><p class="sub">The original is never changed. The standard value is only added beside it.</p></div></div>
  <div class="tw">
  <table class="tbl">
    <thead><tr><th>Field</th><th>As the ad wrote it</th><th>Standardized</th><th>Where it came from</th></tr></thead>
    <tbody>
      <tr><td>Title</td><td>{{ $job->rawPost?->raw_title ?? $job->title }}</td><td>{{ $job->match_title ?? '-' }} <span class="mut">(for matching)</span></td><td class="mut">{{ $how[$sources['title'] ?? ''] ?? '' }}</td></tr>
      <tr><td>Employer</td><td>{{ $job->employer_text ?? '-' }}</td><td>{{ $employer ?? '-' }}</td><td class="mut">{{ $how[$sources['employer_text'] ?? ''] ?? '' }}</td></tr>
      <tr><td>Place</td><td>{{ $job->location_text ?? '-' }}</td><td>{{ $place ?? '-' }}@if ($job->location_multiple) <span class="pill warn">several places</span>@endif</td><td class="mut">{{ $how[$sources['location_text'] ?? ''] ?? '' }}</td></tr>
      <tr><td>Qualification</td><td>{{ $job->qualification_text ?? '-' }}</td><td>{{ $job->qualification_label ?? '-' }}</td><td class="mut">{{ $how[$sources['qualification_text'] ?? ''] ?? '' }}</td></tr>
      <tr><td>Experience</td><td>{{ $job->experience_text ?? '-' }}</td><td>{{ $job->experience_label ?? '-' }}</td><td class="mut">{{ $how[$sources['experience_text'] ?? ''] ?? '' }}</td></tr>
      <tr><td>Job type</td><td>{{ $job->employment_type ?? '-' }}</td><td>{{ $typeLabels ?: '-' }}</td><td class="mut">{{ $how[$sources['employment_type'] ?? ''] ?? '' }}</td></tr>
      <tr><td>Salary</td><td>{{ $job->salary_text ?? '-' }}</td><td>@if ($job->salary_min !== null){{ $job->salary_currency }} {{ number_format($job->salary_min) }}@if ($job->salary_max && $job->salary_max != $job->salary_min)–{{ number_format($job->salary_max) }}@endif @else - @endif</td><td class="mut">{{ $how[$sources['salary_text'] ?? ''] ?? '' }}</td></tr>
      <tr><td>Posted</td><td colspan="2">{{ $job->posted_date?->format('j F Y') ?? 'not given' }}</td><td class="mut">{{ $how[$sources['posted_date'] ?? ''] ?? '' }}</td></tr>
      <tr><td>Closes</td><td colspan="2">{{ $job->closing_date?->format('j F Y') ?? 'not given' }}@if ($job->end_assumed && $job->ends_on) &middot; assumed end {{ $job->ends_on->format('j F Y') }}@endif</td><td class="mut">{{ $how[$sources['closing_date'] ?? ''] ?? '' }}</td></tr>
      <tr><td>Source</td><td colspan="3">@if ($src)<a class="name" href="{{ route('sources.show', $src) }}">{{ $src->name }}</a> <span class="mut">{{ \App\Models\Source::SOURCE_TYPES[$src->source_type] ?? $src->source_type }}</span>@endif &middot; first seen {{ $job->first_seen_at?->format('j M Y') }}, last seen {{ $job->last_seen_at?->diffForHumans() }}</td></tr>
      <tr><td>Application link</td><td colspan="3">{{ $job->application_url ?? 'not given' }}</td></tr>
      <tr><td>Confidence</td><td colspan="3">{{ $job->confidence }} / 100 &middot; {{ $job->review_status === 'needs_review' ? 'needs review' : ($job->review_status === 'reviewed' ? 'reviewed by you' : 'automatic') }}</td></tr>
    </tbody>
  </table>
  </div>
  @if ($waiting->isNotEmpty())
    <p class="sub" style="margin-top:12px">{{ $waiting->count() }} {{ Str::plural('value', $waiting->count()) }} could not be standardized: {{ $waiting->pluck('sample')->implode('; ') }}. <a href="{{ route('values.index') }}">Map them</a>.</p>
  @endif
</section>

@if ($copies->isNotEmpty() || $pairs->isNotEmpty())
<section class="card">
  <div class="hd"><div><h3>Same vacancy elsewhere</h3><p class="sub">Copies keep their own source, link and dates.</p></div></div>
  @foreach ($copies as $copy)
    <div class="act"><div class="ic"><svg class="i"><use href="#users"/></svg></div>
      <div><a class="name" href="{{ route('jobs.show', $copy) }}">{{ $copy->title }}</a> <span class="mut">on {{ $copy->rawPost?->source?->name }}</span><br><small>{{ $copy->status_reason }}</small></div></div>
  @endforeach
  @foreach ($pairs as $p)
    @php $other = $others[$p->job_a_id === $job->id ? $p->job_b_id : $p->job_a_id] ?? null; @endphp
    @continue(! $other || $copies->contains('id', $other->id))
    <div class="act"><div class="ic {{ $p->outcome === 'rejected' ? 'bad' : 'warn' }}"><svg class="i"><use href="#find"/></svg></div>
      <div><a class="name" href="{{ route('jobs.show', $other) }}">{{ $other->title }}</a> <span class="mut">on {{ $other->rawPost?->source?->name }}</span><br>
        <small>Score {{ $p->score }} &middot; {{ ['possible' => 'waiting for your decision', 'auto' => 'matched automatically', 'confirmed' => 'you confirmed it', 'rejected' => 'you said it is different'][$p->outcome] ?? $p->outcome }}</small></div></div>
  @endforeach
</section>
@endif

<section class="split">
  <div class="card">
    <div class="hd"><div><h3>Status history</h3><p class="sub">Why this job has the status it has</p></div></div>
    @foreach ($history as $h)
      <div class="act"><div class="ic"><svg class="i"><use href="#clock"/></svg></div>
        <div><b>{{ $h->from_status ? ucfirst($h->from_status).' to ' : '' }}{{ ucfirst($h->to_status) }}</b> <span class="mut">by {{ $h->changed_by }}, {{ \Illuminate\Support\Carbon::parse($h->created_at)->diffForHumans() }}</span><br><small>{{ $h->reason }}</small></div></div>
    @endforeach
  </div>
  <div class="card">
    <div class="hd"><div><h3>Reading log</h3><p class="sub">What was and was not found in the ad</p></div></div>
    @forelse ($logs as $log)
      <div class="act"><div class="ic {{ $log->status === 'ok' ? 'ok' : ($log->status === 'error' ? 'bad' : 'warn') }}"><svg class="i"><use href="#{{ $log->status === 'error' ? 'x' : 'chk' }}"/></svg></div>
        <div><b>{{ ucfirst($log->status) }}</b> <span class="mut">{{ \Illuminate\Support\Carbon::parse($log->created_at)->diffForHumans() }}</span><br><small>{{ $log->message ?? 'Everything looked for was found.' }}</small></div></div>
    @empty
      <p class="empty">Nothing logged.</p>
    @endforelse
  </div>
</section>
@endsection
