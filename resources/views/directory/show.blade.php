@extends('layouts.app')
@section('title', $q->name)
@section('heading', $q->name)
@section('subheading', ucfirst(str_replace('_', ' ', $q->kind)).($q->level ? ' · '.$q->level->name : '').($field ? ' · '.($field->parent ? $field->parent.' › ' : '').$field->name : ''))
@section('content')
@php
    $statusClass = ['accredited' => '', 'registered' => '', 'recognised' => '', 'not_recognised' => 'bad', 'unknown' => 'mut'];
    $pct = fn (int $n, int $of) => $of > 0 ? round($n / $of * 100) : 0;
    $checked = fn ($when) => $when ? \Illuminate\Support\Carbon::parse($when)->format('j M Y') : null;
@endphp

<p><a href="{{ route('directory.index') }}">← All qualifications</a></p>

<section class="card">
  <div class="hd"><div><h3>About</h3>
    <p class="sub">Four separate facts follow: it exists, it is recognised, it can be studied, employers ask for it. An advertisement only shows the last one.</p></div>
    <span class="pill {{ $q->status === 'verified' ? '' : 'mut' }}">{{ ucfirst($q->status) }}</span></div>
  <dl class="kv">
    <dt>Awarded by</dt><dd>{{ $q->awardingOrganization?->name ?? 'Not recorded' }}</dd>
    <dt>Typical duration</dt><dd>{{ $q->typical_duration_months ? $q->typical_duration_months.' months' : 'Not verified' }}</dd>
    <dt>Entry requirements</dt><dd>{{ $q->entry_requirements ?: 'Not verified' }}</dd>
    @if ($q->aliases)<dt>Also written as</dt><dd>{{ implode(', ', $q->aliases) }}</dd>@endif
  </dl>
  @if ($q->status !== 'verified')
    <p class="sub" style="margin-top:10px">This entry comes from the wording found in advertisements. Nobody has checked it against a source yet.</p>
  @endif
</section>

<section class="split2" style="grid-template-columns:1fr 1fr">
  <div class="card">
    <div class="hd"><div><h3>Recognition in Zambia</h3><p class="sub">What an authority says, with its source</p></div></div>
    @forelse ($recognitions as $r)
      <div class="rm"><div class="h"><b>{{ $r->authority->name }}</b><span class="pill {{ $statusClass[$r->status] ?? 'mut' }}">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</span></div>
        <small>{{ $r->scope }}@if ($r->reference) · Ref. {{ $r->reference }}@endif @if ($r->valid_to) · valid to {{ $r->valid_to->format('j M Y') }}@endif
          · {{ $r->verified_at ? 'verified '.$r->verified_at->format('j M Y') : 'not verified' }}</small></div>
    @empty
      <p class="empty">No recognition is recorded. Employers asking for it does not make it recognised.</p>
    @endforelse
  </div>

  <div class="card">
    <div class="hd"><div><h3>Where it can be studied</h3><p class="sub">Providers and programmes</p></div></div>
    @forelse ($programmes as $p)
      <div class="rm"><div class="h"><b>{{ $p->provider->name }}</b><em>{{ $p->duration_months ? $p->duration_months.' mo' : '' }}</em></div>
        <small>{{ $p->name }}@if ($p->mode) · {{ ucfirst(str_replace('_', ' ', $p->mode)) }}@endif @if ($p->location_id) · {{ $locations[$p->location_id] ?? '' }}@endif
          @if ($p->accreditation_status) · {{ ucfirst(str_replace('_', ' ', $p->accreditation_status)) }}@endif · {{ $p->verified_at ? 'verified '.$p->verified_at->format('j M Y') : 'not verified' }}</small></div>
    @empty
      <p class="empty">No provider is recorded yet.</p>
    @endforelse
  </div>
</section>

<section class="card">
  <div class="hd"><div><h3>Employer demand</h3><p class="sub">Vacancies that ask for it, each counted once (copies and rejected ads left out)</p></div></div>
  @if ($demand['vacancies'] === 0)
    <p class="empty">No vacancy asks for this qualification yet.</p>
  @else
    <div class="g4 st">
      <div class="kpi"><span class="kl">Vacancies</span><b class="kv">{{ number_format($demand['vacancies']) }}</b></div>
      <div class="kpi"><span class="kl">Required, not just preferred</span><b class="kv">{{ $pct($demand['required'], $demand['vacancies']) }}%</b><div class="kc"><small>{{ $demand['required'] }} of {{ $demand['vacancies'] }}</small></div></div>
      <div class="kpi"><span class="kl">Open now</span><b class="kv">{{ number_format($demand['open']) }}</b></div>
    </div>

    @if ($wordings->isNotEmpty())
      <h4 style="margin:18px 0 6px">How employers wrote it</h4>
      @foreach ($wordings as $w)<p class="sub" style="margin:3px 0">“{{ $w->evidence }}” <em>×{{ $w->n }}</em> · <a href="{{ route('jobs.show', $w->job_id) }}">example</a></p>@endforeach
    @endif

    <h4 style="margin:18px 0 6px">What goes with it</h4>
    @if ($links['sample'] < $minSample)
      <p class="empty">Only {{ $links['sample'] }} {{ Str::plural('vacancy', $links['sample']) }} counted so far. Links are shown from {{ $minSample }}, so a handful of ads is not mistaken for a pattern.</p>
    @else
      <p class="sub">Shares of the {{ $links['sample'] }} vacancies asking for it{{ $links['computed_at'] ? ', counted '.$checked($links['computed_at']) : '' }}. These show what ads have in common, not where the qualification leads.</p>
      <div class="split2" style="grid-template-columns:repeat(3,1fr)">
        @foreach (['occupation' => 'Occupations', 'skill' => 'Skills', 'industry' => 'Industries'] as $type => $title)
          <div>
            <b>{{ $title }}</b>
            @forelse ($links[$type] as $row)
              <div class="rm"><div class="h"><b>{{ $row->name }}</b><span>{{ $row->share }}%</span><em>{{ $row->jobs }}</em></div>
                <div class="bar"><i style="width:{{ max(3, $row->share) }}%"></i></div></div>
            @empty
              <p class="empty">None recorded.</p>
            @endforelse
          </div>
        @endforeach
      </div>
    @endif
  @endif
</section>

@if ($family)
<section class="card">
  <div class="hd"><div><h3>Same field family</h3><p class="sub">{{ $family['name'] }}: other entries at this level in related fields. They stay separate entries; this only relates them.</p></div></div>
  @foreach ($family['members'] as $m)
    <p style="margin:4px 0"><a href="{{ route('directory.show', $m->id) }}">{{ $m->name }}</a> <small>· {{ $m->vacancies }} {{ Str::plural('vacancy', $m->vacancies) }}</small></p>
  @endforeach
  <p class="sub" style="margin-top:10px">Counting the whole family, <b>{{ $family['vacancies'] }}</b> {{ Str::plural('vacancy', $family['vacancies']) }} ask for one of these, each counted once.</p>
</section>
@endif

@if ($relations->isNotEmpty())
<section class="card">
  <div class="hd"><div><h3>Related qualifications</h3></div></div>
  @foreach ($relations as $rel)
    <p style="margin:4px 0">{{ ucfirst($rel->type) }}{{ $rel->from_id === $q->id ? ' to ' : ' from ' }}<a href="{{ route('directory.show', $rel->other_id) }}">{{ $rel->other_name }}</a>@if ($rel->note) <small>· {{ $rel->note }}</small>@endif</p>
  @endforeach
</section>
@endif

<section class="card">
  <div class="hd"><div><h3>Sources</h3><p class="sub">Where the facts above come from</p></div></div>
  @forelse ($evidence as $e)
    <p style="margin:4px 0"><b>{{ ucfirst($e->claim) }}</b> ·
      @if ($e->url && preg_match('~^https?://~i', $e->url))<a href="{{ $e->url }}" rel="noopener noreferrer nofollow" target="_blank">{{ Str::limit($e->url, 70) }}</a>@else{{ $e->document ?? 'No link' }}@endif
      <small>@if ($e->retrieved_on) · retrieved {{ $checked($e->retrieved_on) }}@endif · {{ $e->verified_at ? 'verified '.$checked($e->verified_at).($e->verified_by ? ' by '.$e->verified_by : '') : 'not verified' }}</small>
      @if ($e->note)<br><small>{{ $e->note }}</small>@endif</p>
  @empty
    <p class="empty">No source is recorded for any fact on this page.</p>
  @endforelse
</section>
@endsection
