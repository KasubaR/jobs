@extends('layouts.app')
@section('title', 'Directory')
@section('heading', 'Qualification directory')
@section('subheading', 'What qualifications exist, who awards them, and what has been checked')
@section('content')
<section class="card">
  <form class="filters" method="GET" action="{{ route('directory.index') }}">
    <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Search by name or alias" aria-label="Search qualifications" maxlength="80">
    <select name="kind" aria-label="Kind">
      <option value="">All qualifications</option>
      @foreach (['academic' => 'Academic', 'professional' => 'Professional', 'registration' => 'Memberships and registrations (not qualifications)', 'general' => 'Degree and diploma types with no subject (not qualifications)', 'vocational' => 'Vocational and trade', 'short_course' => 'Short course'] as $k => $label)
        <option value="{{ $k }}" @selected($filters['kind'] === $k)>{{ $label }}</option>
      @endforeach
    </select>
    <button class="btn" type="submit">Search</button>
    @if ($filters['q'] !== '' || $filters['kind'])<a class="btn" href="{{ route('directory.index') }}">Clear</a>@endif
  </form>

  @if ($qualifications->isEmpty())
    <p class="empty">No qualification matches.</p>
  @else
    <table class="tbl">
      <thead><tr><th>Qualification</th><th>Kind</th><th>Level</th><th>Awarded by</th><th>Checked</th></tr></thead>
      <tbody>
      @foreach ($qualifications as $item)
        <tr>
          <td><a href="{{ route('directory.show', $item) }}">{{ $item->name }}</a></td>
          <td>{{ ucfirst(str_replace('_', ' ', $item->kind)) }}</td>
          <td>{{ $item->level?->name ?? '—' }}</td>
          <td>{{ $item->awardingOrganization?->name ?? '—' }}</td>
          <td><span class="pill {{ $item->status === 'verified' ? '' : 'mut' }}">{{ ucfirst($item->status) }}</span></td>
        </tr>
      @endforeach
      </tbody>
    </table>
    <div style="margin-top:14px">{{ $qualifications->links() }}</div>
  @endif
</section>
@endsection
