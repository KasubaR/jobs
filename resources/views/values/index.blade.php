@extends('layouts.app')
@section('title', 'Values to map')
@section('heading', 'Values to map')
@section('subheading', 'Text the rules could not standardize. One decision fixes every job waiting on it.')
@section('content')
@php $total = $rows->flatten(1)->count(); @endphp

<section class="card">
  <p class="sub" style="margin:0">
    The original text is never changed: a decision here only adds the standard value beside it, and the same text is understood from then on.
    @if ($mapped) You have made {{ $mapped }} {{ Str::plural('decision', $mapped) }} so far. @endif
    <b>Not applicable</b> means the text names nothing to standardize (for example "Head office" as a place); it is not asked about again.
  </p>
</section>

@if ($total === 0)
  <section class="card"><p class="empty">Nothing is waiting. Every qualification, experience, job type and place the rules met could be standardized.</p></section>
@endif

@foreach ($kinds as $kind => $title)
  @continue(! isset($rows[$kind]))
  <section class="card">
    <div class="hd"><div><h3>{{ $title }}</h3><p class="sub">{{ $rows[$kind]->count() }} different {{ Str::plural('value', $rows[$kind]->count()) }} waiting</p></div></div>
    <div class="tw">
      <table class="tbl">
        <thead><tr><th>As the ad wrote it</th><th>Jobs</th><th>Why</th><th>Standardize as</th></tr></thead>
        <tbody>
        @foreach ($rows[$kind] as $row)
          <tr>
            <td><b>{{ $row->sample }}</b></td>
            <td class="nowrap">{{ $row->jobs }}</td>
            <td><span class="pill {{ $row->reason === 'ambiguous' ? 'warn' : 'mut' }}">{{ $row->reason === 'ambiguous' ? 'Names more than one' : 'Not known yet' }}</span></td>
            <td>
              <form method="POST" action="{{ route('values.store') }}" class="btns">
                @csrf
                <input type="hidden" name="kind" value="{{ $kind }}">
                <input type="hidden" name="normalized" value="{{ $row->normalized }}">
                @if ($kind === 'qualification')
                  <select name="level" aria-label="Level" required><option value="">Level…</option>@foreach ($levels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                  <input type="text" name="field" maxlength="120" placeholder="Field of study (optional)" aria-label="Field of study">
                @elseif ($kind === 'field')
                  <input type="text" name="subject" list="subjects" maxlength="120" placeholder="Which subject is this?" aria-label="Subject" required>
                @elseif ($kind === 'experience')
                  <input type="number" name="min_years" step="0.5" min="0" max="60" placeholder="From (years)" aria-label="Minimum years" style="width:9rem" required>
                  <input type="number" name="max_years" step="0.5" min="0" max="60" placeholder="To (optional)" aria-label="Maximum years" style="width:9rem">
                @elseif ($kind === 'employment_type')
                  <select name="type" aria-label="Job type" required><option value="">Type…</option>@foreach ($types as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                @else
                  <select name="location_id" aria-label="Place" required><option value="">Place…</option>@foreach ($places as $place)<option value="{{ $place->id }}">{{ $place->name }}{{ $place->type === 'province' ? ' (province)' : '' }}</option>@endforeach</select>
                @endif
                <button class="btn primary sm" type="submit" name="action" value="map">Save</button>
                <button class="btn sm" type="submit" name="action" value="ignore" formnovalidate>Not applicable</button>
              </form>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </section>
@endforeach
<datalist id="subjects">@foreach ($subjects as $name)<option value="{{ $name }}">@endforeach</datalist>
@endsection
