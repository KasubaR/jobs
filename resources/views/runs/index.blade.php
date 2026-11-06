@extends('layouts.app')
@section('title', 'Collection runs')
@section('heading', 'Collection runs')
@section('subheading', 'Every collection attempt, newest first')
@section('content')
<section class="card">
  <form class="filters" method="GET" action="{{ route('runs.index') }}">
    <select name="status" aria-label="Status"><option value="">Any status</option>
      @foreach (['success', 'partial', 'failed', 'running'] as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ ucfirst($s) }}</option>@endforeach</select>
    <select name="source" aria-label="Source"><option value="">Any source</option>
      @foreach ($sources as $id => $name)<option value="{{ $id }}" @selected((string) ($filters['source'] ?? '') === (string) $id)>{{ $name }}</option>@endforeach</select>
    <button class="btn" type="submit">Filter</button>
    @if (array_filter($filters))<a class="btn" href="{{ route('runs.index') }}">Clear</a>@endif
  </form>
  @include('runs._table', ['runs' => $runs, 'showSource' => true])
  {{ $runs->links() }}
</section>
@endsection
