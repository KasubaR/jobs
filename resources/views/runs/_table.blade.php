{{-- Discovered = found on the site. Collected = new + changed + unchanged. Failed = found but could not be read or stored. --}}
<div class="tw"><table class="tbl">
  <thead><tr>
    <th>Started</th>@if ($showSource)<th>Source</th>@endif<th>Trigger</th><th>Status</th>
    <th title="Ads found on the site">Discovered</th><th title="New + changed + unchanged">Collected</th>
    <th>New</th><th>Changed</th><th>Unchanged</th><th title="Found, but could not be read or stored">Failed</th><th>Duration</th><th>Error</th>
  </tr></thead>
  <tbody>
  @forelse ($runs as $run)
    <tr>
      <td class="nowrap"><a class="name" href="{{ route('runs.show', $run) }}">{{ $run->started_at->format('j M Y H:i') }}</a></td>
      @if ($showSource)<td>@if ($run->source)<a class="name" href="{{ route('sources.show', $run->source) }}">{{ $run->source->name }}</a>@else<span class="mut">removed</span>@endif</td>@endif
      <td>{{ $run->trigger }}</td>
      <td><span @class(['pill', 'bad' => $run->status === 'failed', 'warn' => $run->status === 'partial', 'mut' => $run->status === 'running'])>{{ $run->status }}</span></td>
      <td>{{ $run->items_found }}</td>
      <td>{{ $run->collectedCount() }}</td>
      <td>{{ $run->items_new }}</td>
      <td>{{ $run->items_updated }}</td>
      <td>{{ $run->items_unchanged }}</td>
      <td>@if ($run->items_failed)<span class="pill bad">{{ $run->items_failed }}</span>@else<span class="mut">0</span>@endif</td>
      <td class="nowrap">{{ $run->durationSeconds() !== null ? $run->durationSeconds().' s' : '-' }}</td>
      <td>{{ \Illuminate\Support\Str::limit($run->error_message, 120) }}</td>
    </tr>
  @empty
    <tr><td colspan="12" class="empty">No runs match.</td></tr>
  @endforelse
  </tbody>
</table></div>
