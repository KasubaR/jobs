@extends('layouts.app')
@php
    use App\Models\Source;
    $manual = $source->collection_method === 'manual';
    $python = $source->collection_method === 'python';
    $ready = $source->is_active && $source->access_status === 'approved';
@endphp
@section('title', $source->name)
@section('heading', $source->name)
@section('subheading', (Source::SOURCE_TYPES[$source->source_type] ?? $source->source_type).' · '.(Source::METHODS[$source->collection_method] ?? $source->collection_method))
@section('content')
<section class="card">
  <div class="hd">
    <div class="btns">
      <span @class(['pill', 'warn' => $source->access_status === 'pending', 'bad' => $source->access_status === 'restricted'])>Access: {{ Source::ACCESS_STATUSES[$source->access_status] ?? $source->access_status }}</span>
      <span @class(['pill', 'mut' => ! $source->is_active])>{{ $source->is_active ? 'Active' : 'Switched off' }}</span>
      @if ($source->consecutive_failures)<span class="pill bad">{{ $source->consecutive_failures }} {{ Str::plural('failure', $source->consecutive_failures) }} in a row</span>@endif
    </div>
    <div class="btns">
      <a class="btn sm" href="{{ route('sources.edit', $source) }}">Edit</a>
      @if ($source->access_status !== 'restricted')
        <form method="POST" action="{{ route('sources.test', $source) }}">@csrf<button class="btn sm" type="submit">Test access</button></form>
      @endif
      @if ($ready && ! $manual && ! $python)
        <form method="POST" action="{{ route('sources.run', $source) }}" data-confirm="Collect from {{ $source->name }} once, right now?">@csrf<button class="btn sm" type="submit">Run now</button></form>
      @endif
      @if ($ready && $manual)
        <button class="btn sm" type="button" data-open="ad-dlg">Add ad</button>
        <button class="btn sm" type="button" data-open="import-dlg">Import file</button>
      @endif
      <button class="btn sm" type="button" data-open="access-dlg">Decide access</button>
      @if ($source->is_active)
        <form method="POST" action="{{ route('sources.deactivate', $source) }}" data-confirm="Switch {{ $source->name }} off?">@csrf<button class="btn sm" type="submit">Deactivate</button></form>
      @else
        <form method="POST" action="{{ route('sources.activate', $source) }}" data-confirm="Switch {{ $source->name }} on?">@csrf<button class="btn sm primary" type="submit">Activate</button></form>
      @endif
      @if ($adsCount === 0)
        <form method="POST" action="{{ route('sources.destroy', $source) }}" data-confirm="Delete {{ $source->name }}? This cannot be undone.">@csrf @method('DELETE')<button class="btn sm danger" type="submit">Delete</button></form>
      @endif
    </div>
  </div>

  <nav class="tabs" aria-label="Source sections">
    @foreach (['overview' => 'Overview', 'runs' => 'Collection history', 'errors' => 'Errors', 'access' => 'Access history'] as $key => $label)
      <a href="{{ route('sources.show', [$source, 'tab' => $key]) }}" @class(['on' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
  </nav>

  @if ($tab === 'overview')
    @if (! $source->is_active && $problems)
      <div class="alert warn"><b>Cannot be switched on yet:</b><ul>@foreach ($problems as $p)<li>{{ $p }}</li>@endforeach</ul></div>
    @endif
    <dl class="kv">
      <dt>Website</dt><dd><a href="{{ $source->website_url }}" target="_blank" rel="noopener noreferrer">{{ $source->website_url }}</a></dd>
      <dt>Job listing</dt><dd>{{ $source->job_listing_url ?? 'none' }}</dd>
      <dt>Collector</dt><dd>{{ $source->collector ?? 'none' }}</dd>
      <dt>Frequency</dt><dd>{{ Source::FREQUENCIES[$source->collection_frequency] ?? $source->collection_frequency }}, at least {{ $source->min_interval_seconds }}s between requests</dd>
      <dt>Last collected</dt><dd>{{ $source->last_collection_at?->format('j M Y H:i') ?? 'never' }}</dd>
      <dt>Next due</dt><dd>{{ $manual ? 'Manual: ads are pasted in' : ($python ? ($source->is_active ? 'Due '.($source->next_collection_at?->format('j M Y H:i') ?? 'now').' (collected by the Python worker on your computer)' : 'switched off') : ($source->is_active ? ($source->next_collection_at?->format('j M Y H:i') ?? 'at the next check') : 'switched off')) }}</dd>
      <dt>Stored ads</dt>
      <dd>
        {{ number_format($adsCount) }}
        @if ($adsCount > 0)
          <span class="mut">
            – {{ number_format($waitingCount) }} waiting to be processed
            @if ($structuredCount > 0)
              , {{ number_format($structuredCount) }} with structured job data
            @endif
          </span>
        @endif
      </dd>
      <dt>Raw ad text</dt><dd>{{ $source->retain_raw ? 'Kept for 90 days' : 'Not kept' }}</dd>
      <dt>Access decided</dt><dd>{{ $source->access_decided_at?->format('j M Y H:i') ?? 'not yet' }}@if ($source->access_decision_note) <div class="mut">{{ $source->access_decision_note }}</div>@endif</dd>
      <dt>Notes</dt><dd>{{ $source->notes ?: 'none' }}</dd>
    </dl>
  @elseif ($tab === 'runs' || $tab === 'errors')
    @if ($tab === 'errors')<p class="sub" style="margin-bottom:10px">Runs that failed or only partly worked. Open a run to see each problem.</p>@endif
    @include('runs._table', ['runs' => $runs, 'showSource' => false])
    {{ $runs->links() }}
  @else
    <div class="tw"><table class="tbl">
      <thead><tr><th>When</th><th>Who</th><th>Changed</th><th>From</th><th>To</th><th>Note</th></tr></thead>
      <tbody>
      @forelse ($logs as $log)
        <tr><td class="nowrap">{{ $log->created_at->format('j M Y H:i') }}</td><td>{{ $log->user?->email ?? 'system' }}</td>
          <td>{{ $log->field === 'is_active' ? 'Active' : 'Access' }}</td><td>{{ $log->from_value ?? '-' }}</td><td>{{ $log->to_value ?? '-' }}</td><td>{{ $log->note ?? '-' }}</td></tr>
      @empty
        <tr><td colspan="6" class="empty">No changes recorded.</td></tr>
      @endforelse
      </tbody></table></div>
    {{ $logs->links() }}
  @endif
</section>

<dialog class="dlg" id="access-dlg" aria-labelledby="access-title">
  <form method="POST" action="{{ route('sources.access', $source) }}">
    @csrf
    <h3 id="access-title">Decide access</h3>
    <p class="sub">Approved means you checked the terms and robots.txt, or have permission. Anything other than Approved also switches the source off.</p>
    <div class="field" style="margin-top:14px"><label for="status">Decision</label>
      <select id="status" name="status" required>@foreach (Source::ACCESS_STATUSES as $k => $v)<option value="{{ $k }}" @selected($source->access_status === $k)>{{ $v }}</option>@endforeach</select></div>
    <div class="acts"><button class="btn" type="button" data-close>Cancel</button><button class="btn primary" type="submit">Save decision</button></div>
  </form>
</dialog>

@if ($manual && $ready)
<dialog class="dlg" id="ad-dlg" aria-labelledby="ad-title">
  <form method="POST" action="{{ route('sources.ads', $source) }}">
    @csrf
    <h3 id="ad-title">Add an ad to {{ $source->name }}</h3>
    <p class="sub">Paste an ad you found yourself, for example from a PDF or newspaper. Email addresses are removed when it is stored. Nothing is fetched from the web.</p>
    <div class="field" style="margin-top:14px"><label for="ad_title">Title</label><input id="ad_title" name="title" type="text" required maxlength="500"><span class="help">Written like the other ads, e.g. "Budget Analyst at World Vision".</span></div>
    <div class="field" style="margin-top:14px"><label for="ad_url">Link (optional)</label><input id="ad_url" name="url" type="url" maxlength="500"><span class="help">Leave empty for a PDF or newspaper notice.</span></div>
    <div class="field" style="margin-top:14px"><label for="ad_content">Ad text</label><textarea id="ad_content" name="content" required maxlength="100000" style="min-height:180px"></textarea></div>
    <div class="acts"><button class="btn" type="button" data-close>Cancel</button><button class="btn primary" type="submit">Add ad</button></div>
  </form>
</dialog>

<dialog class="dlg" id="import-dlg" aria-labelledby="import-title">
  <form method="POST" action="{{ route('sources.import', $source) }}" enctype="multipart/form-data">
    @csrf
    <h3 id="import-title">Import ads into {{ $source->name }}</h3>
    <p class="sub">Upload a <b>.csv</b> or <b>.json</b> file of ads. They are stored as one run, with the same rules as a pasted ad: email addresses are removed and nothing is fetched from the web. A bad row is reported with its row number and the rest are still stored.</p>
    <p class="sub" style="margin-top:10px"><b>CSV columns:</b> <code>title</code> and <code>content</code> are required; <code>url</code>, <code>external_id</code> and <code>structured</code> (JSON facts) are optional. First row = column names. At most {{ config('job_intel.import_max_rows') }} ads and {{ round(config('job_intel.import_max_bytes') / 1048576) }} MB per file.
      <a href="{{ route('sources.import-sample', $source) }}">Download a sample CSV</a></p>
    <div class="field" style="margin-top:14px"><label for="import_file">File</label><input id="import_file" name="file" type="file" accept=".csv,.json,text/csv,application/json" required></div>
    <div class="acts"><button class="btn" type="button" data-close>Cancel</button><button class="btn primary" type="submit">Import</button></div>
  </form>
</dialog>
@endif
@endsection
