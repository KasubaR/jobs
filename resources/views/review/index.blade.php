@extends('layouts.app')
@section('title', 'Review readings')
@section('heading', 'Review readings')
@section('subheading', 'Wording in ads that the reader could not place. One decision settles every vacancy that used it.')
@section('content')
<section class="card">
  <p class="sub" style="margin:0">
    The reader never forces unfamiliar wording onto the nearest field. Here you say what it means. <b>Map to a field</b> also teaches the matcher, so the same wording is understood in future ads.
    <b>Not a qualification</b> removes it from the vacancies. <b>Keep as written</b> accepts it as it is.
    The ad's own sentence is never changed.
    @if ($decided) You have decided {{ $decided }} {{ Str::plural('item', $decided) }} so far. @endif
  </p>
</section>

@if ($rows->isEmpty())
  <section class="card"><p class="empty">Nothing is waiting. Every qualification the reader met could be placed, or a person has decided it.</p></section>
@else
<section class="card">
  <div class="hd"><div><h3>Wording to place</h3><p class="sub">{{ $rows->count() }} different {{ Str::plural('wording', $rows->count()) }}, most common first</p></div></div>
  <div class="tw">
    <table class="tbl">
      <thead><tr><th>Field wording</th><th>Level</th><th>Vacancies</th><th>In the ad</th><th>Decide</th></tr></thead>
      <tbody>
      @foreach ($rows as $row)
        <tr>
          <td><b>{{ $row->sample }}</b></td>
          <td class="nowrap">{{ $levels[$row->level] ?? '—' }}</td>
          <td class="nowrap"><a href="{{ route('jobs.show', $row->job_id) }}">{{ $row->jobs }}</a></td>
          <td><small>{{ $row->evidence ? Str::limit($row->evidence, 110) : 'Ad text no longer stored' }}</small></td>
          <td>
            <form method="POST" action="{{ route('review.store') }}" class="btns">
              @csrf
              <input type="hidden" name="wording" value="{{ $row->wording }}">
              <input type="hidden" name="level" value="{{ $row->level }}">
              @if ($row->level)
                <select name="field_id" aria-label="Field of study">
                  <option value="">Field…</option>
                  @foreach ($fields as $f)<option value="{{ $f->id }}">{{ $f->parent ? $f->parent.' › ' : '' }}{{ $f->name }}</option>@endforeach
                </select>
                <button class="btn primary sm" type="submit" name="action" value="map">Map to field</button>
              @endif
              <button class="btn sm" type="submit" name="action" value="remove" formnovalidate>Not a qualification</button>
              <button class="btn sm" type="submit" name="action" value="keep" formnovalidate>Keep as written</button>
            </form>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</section>
@endif
@endsection
