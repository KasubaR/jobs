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
  <div class="tw"><table class="tbl">
    <thead><tr><th>Started</th><th>Source</th><th>Trigger</th><th>Status</th><th>Found</th><th>New</th><th>Updated</th><th>Error</th></tr></thead>
    <tbody>
    @forelse ($runs as $run)
      <tr><td class="nowrap">{{ $run->started_at->format('j M Y H:i') }}</td>
        <td>@if ($run->source)<a class="name" href="{{ route('sources.show', $run->source) }}">{{ $run->source->name }}</a>@else<span class="mut">removed</span>@endif</td>
        <td>{{ $run->trigger }}</td>
        <td><span @class(['pill', 'bad' => $run->status === 'failed', 'warn' => $run->status === 'partial', 'mut' => $run->status === 'running'])>{{ $run->status }}</span></td>
        <td>{{ $run->items_found }}</td><td>{{ $run->items_new }}</td><td>{{ $run->items_updated }}</td><td>{{ $run->error_message }}</td></tr>
    @empty
      <tr><td colspan="8" class="empty">No runs match.</td></tr>
    @endforelse
    </tbody></table></div>
  {{ $runs->links() }}
</section>
@endsection
