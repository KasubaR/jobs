@extends('layouts.app')
@section('title', 'Sources')
@section('heading', 'Sources')
@section('subheading', 'Websites ads are collected from')
@section('content')
<section class="card">
  <div class="hd">
    <div><h3>All sources</h3><p class="sub">{{ $sources->total() }} {{ Str::plural('source', $sources->total()) }}</p></div>
    <a class="btn primary" href="{{ route('sources.create') }}"><svg class="i"><use href="#plus"/></svg>Add source</a>
  </div>
  <form class="filters" method="GET" action="{{ route('sources.index') }}">
    <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search by name" aria-label="Search by name" maxlength="100">
    <select name="access" aria-label="Access"><option value="">Any access</option>
      @foreach (\App\Models\Source::ACCESS_STATUSES as $k => $v)<option value="{{ $k }}" @selected(($filters['access'] ?? '') === $k)>{{ $v }}</option>@endforeach</select>
    <select name="active" aria-label="Active"><option value="">Active or not</option><option value="1" @selected(($filters['active'] ?? '') === '1')>Active</option><option value="0" @selected(($filters['active'] ?? '') === '0')>Inactive</option></select>
    <label class="check"><input type="checkbox" name="failing" value="1" @checked(! empty($filters['failing']))> Failing only</label>
    <button class="btn" type="submit">Filter</button>
    @if (array_filter($filters))<a class="btn" href="{{ route('sources.index') }}">Clear</a>@endif
  </form>
  <div class="tw">
  <table class="tbl">
    <thead><tr><th>Source</th><th>Method</th><th>Frequency</th><th>Access</th><th>Active</th><th>Last run</th><th>Last collected</th><th>Failures</th></tr></thead>
    <tbody>
    @forelse ($sources as $source)
      <tr>
        <td><a class="name" href="{{ route('sources.show', $source) }}">{{ $source->name }}</a><div class="mut">{{ \App\Models\Source::SOURCE_TYPES[$source->source_type] ?? $source->source_type }}@if ($source->collector) &middot; {{ $source->collector }}@endif</div></td>
        <td>{{ \App\Models\Source::METHODS[$source->collection_method] ?? $source->collection_method }}</td>
        <td>{{ \App\Models\Source::FREQUENCIES[$source->collection_frequency] ?? $source->collection_frequency }}</td>
        <td><span @class(['pill', 'warn' => $source->access_status === 'pending', 'bad' => $source->access_status === 'restricted'])>{{ \App\Models\Source::ACCESS_STATUSES[$source->access_status] ?? $source->access_status }}</span></td>
        <td><span @class(['pill', 'mut' => ! $source->is_active])>{{ $source->is_active ? 'Active' : 'Off' }}</span></td>
        <td>@if ($source->last_run_status)<span @class(['pill', 'bad' => $source->last_run_status === 'failed', 'warn' => $source->last_run_status === 'partial'])>{{ $source->last_run_status }}</span>@else<span class="mut">never</span>@endif</td>
        <td class="nowrap">{{ $source->last_collection_at?->diffForHumans() ?? 'never' }}</td>
        <td>@if ($source->consecutive_failures)<span class="pill bad">{{ $source->consecutive_failures }}</span>@else<span class="mut">0</span>@endif</td>
      </tr>
    @empty
      <tr><td colspan="8" class="empty">No sources match.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
  {{ $sources->links() }}
</section>
@endsection
