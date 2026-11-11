@extends('layouts.app')
@section('title', 'Vacancies')
@section('heading', 'Vacancies')
@section('subheading', 'The ads behind the numbers: one record per vacancy, to check and correct')
@section('content')
<section class="card">
  <div class="hd">
    <div><h3>All jobs</h3><p class="sub">{{ $jobs->total() }} {{ Str::plural('job', $jobs->total()) }}@if (array_filter($filters)) matching the filters @endif</p></div>
    @if ($needsReview)
      <a class="btn" href="{{ route('jobs.index', ['review' => 'needs_review']) }}"><svg class="i"><use href="#eye"/></svg>{{ $needsReview }} to review</a>
    @endif
  </div>

  <div class="btns" style="margin-bottom:14px">
    @foreach (\App\Models\Job::STATUSES as $s)
      @continue($s === 'processing')
      <a class="btn sm {{ ($filters['status'] ?? '') === $s ? 'primary' : '' }}" href="{{ route('jobs.index', ['status' => $s]) }}">{{ ucfirst($s) }} <b>{{ $counts[$s] ?? 0 }}</b></a>
    @endforeach
  </div>

  <form class="filters" method="GET" action="{{ route('jobs.index') }}">
    <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search title or employer" aria-label="Search title or employer" maxlength="100">
    <select name="status" aria-label="Status"><option value="">Any status</option>@foreach (\App\Models\Job::STATUSES as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ ucfirst($s) }}</option>@endforeach</select>
    <select name="source" aria-label="Source"><option value="">Any source</option>@foreach ($sources as $id => $name)<option value="{{ $id }}" @selected((string) ($filters['source'] ?? '') === (string) $id)>{{ $name }}</option>@endforeach</select>
    <label class="check"><input type="checkbox" name="review" value="needs_review" @checked(! empty($filters['review']))> Needs review only</label>
    <button class="btn" type="submit">Filter</button>
    @if (array_filter($filters))<a class="btn" href="{{ route('jobs.index') }}">Clear</a>@endif
  </form>

  <form method="POST" action="{{ route('jobs.decideMany') }}" id="bulk">
  @csrf
  @error('jobs')<div class="alert warn">{{ $message }}</div>@enderror
  @error('reason')<div class="alert warn">{{ $message }}</div>@enderror
  <div class="btns" style="margin-bottom:12px">
    <span class="mut"><span id="picked">0</span> ticked</span>
    <input type="text" name="reason" maxlength="255" placeholder="Reason (optional)" aria-label="Reason" style="min-width:16rem">
    <button class="btn primary sm" type="submit" name="status" value="published">Publish ticked</button>
    <button class="btn danger sm" type="submit" name="status" value="rejected" data-confirm="Reject all ticked jobs?">Reject ticked</button>
  </div>
  <div class="tw">
  <table class="tbl">
    <thead><tr><th style="width:1%"><input type="checkbox" id="pick-all" aria-label="Tick all jobs on this page"></th><th>Job</th><th>Employer</th><th>Place</th><th>Closes</th><th>Status</th><th>Source</th><th>Confidence</th></tr></thead>
    <tbody>
    @forelse ($jobs as $job)
      <tr>
        <td>@if ($job->status !== 'duplicate')<input type="checkbox" class="pick" name="jobs[]" value="{{ $job->id }}" aria-label="Tick {{ $job->title }}">@endif</td>
        <td><a class="name" href="{{ route('jobs.show', $job) }}">{{ $job->title }}</a>
          @if ($job->review_status === 'needs_review')<div><span class="pill warn">Needs review</span></div>@endif</td>
        <td>{{ $job->employer_text ?? '-' }}</td>
        <td>{{ $places[$job->location_id] ?? $job->location_text ?? '-' }}</td>
        <td class="nowrap">{{ $job->closing_date?->format('j M Y') ?? ($job->ends_on ? '~'.$job->ends_on->format('j M Y') : '-') }}@if ($job->end_assumed)<div class="mut">assumed</div>@endif</td>
        <td>@include('partials.job-status', ['status' => $job->status])</td>
        <td>{{ $job->rawPost?->source?->name ?? '-' }}</td>
        <td class="nowrap">{{ $job->confidence }}</td>
      </tr>
    @empty
      <tr><td colspan="8" class="empty">No jobs yet. They appear once processing is switched on and ads have been collected.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  </form>
  {{ $jobs->links() }}
</section>
@endsection
