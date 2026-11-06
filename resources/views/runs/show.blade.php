@extends('layouts.app')
@php use App\Models\SourceRunError; @endphp
@section('title', 'Run '.$run->started_at->format('j M Y H:i'))
@section('heading', 'Collection run')
@section('subheading', ($run->source?->name ?? 'Removed source').' · '.$run->started_at->format('j M Y H:i'))
@section('content')
<section class="card">
  <div class="hd">
    <div class="btns">
      <span @class(['pill', 'bad' => $run->status === 'failed', 'warn' => $run->status === 'partial', 'mut' => $run->status === 'running'])>{{ ucfirst($run->status) }}</span>
      <span class="pill mut">{{ $run->trigger }}</span>
    </div>
    <div class="btns">
      <a class="btn sm" href="{{ route('runs.index') }}">All runs</a>
      @if ($run->source)<a class="btn sm" href="{{ route('sources.show', $run->source) }}">Source page</a>@endif
    </div>
  </div>
  <dl class="kv">
    <dt>Source</dt><dd>@if ($run->source)<a href="{{ route('sources.show', $run->source) }}">{{ $run->source->name }}</a>@else removed @endif</dd>
    <dt>Started</dt><dd>{{ $run->started_at->format('j M Y H:i:s') }}</dd>
    <dt>Finished</dt><dd>{{ $run->finished_at?->format('j M Y H:i:s') ?? 'not finished' }}@if ($run->durationSeconds() !== null) ({{ $run->durationSeconds() }} s)@endif</dd>
    <dt>Discovered</dt><dd>{{ $run->items_found }} {{ Str::plural('ad', $run->items_found) }} found on the site</dd>
    <dt>Collected</dt><dd>{{ $run->collectedCount() }}: {{ $run->items_new }} new, {{ $run->items_updated }} changed, {{ $run->items_unchanged }} unchanged</dd>
    <dt>Failed</dt><dd>{{ $run->items_failed }} found but could not be read or stored</dd>
    <dt>HTTP errors</dt><dd>{{ $run->http_errors }}</dd>
    <dt>Summary</dt><dd>{{ $run->error_message ?: 'No problems.' }}</dd>
  </dl>
</section>

<section class="card">
  <div class="hd"><div><h3>Problems</h3><p class="sub">Everything that went wrong in this run, one row each</p></div>
    @if ($problems->count())<span class="tag">{{ $problems->count() }}</span>@endif</div>
  <div class="tw"><table class="tbl">
    <thead><tr><th>When</th><th>Stage</th><th>Ad</th><th>Message</th></tr></thead>
    <tbody>
    @forelse ($problems as $error)
      <tr>
        <td class="nowrap">{{ $error->created_at?->format('H:i:s') }}</td>
        <td>{{ SourceRunError::STAGES[$error->stage] ?? $error->stage }}</td>
        <td style="overflow-wrap:anywhere">@if ($error->source_url)@if (preg_match('~^https?://~i', $error->source_url))<a href="{{ $error->source_url }}" target="_blank" rel="noopener noreferrer">{{ $error->source_url }}</a>@else{{ $error->source_url }}@endif @else<span class="mut">-</span>@endif</td>
        <td style="overflow-wrap:anywhere">{{ $error->message }}</td>
      </tr>
    @empty
      <tr><td colspan="4" class="empty">No problems in this run.</td></tr>
    @endforelse
    </tbody>
  </table></div>
</section>
@endsection
