@extends('layouts.app')
@section('title', 'Employers')
@section('heading', 'Employers')
@section('subheading', 'Say which industry an employer is in, once, and it applies to all their vacancies')
@section('content')
<section class="card">
  <p class="sub" style="margin:0">
    {{ number_format($assigned) }} of {{ number_format($total) }} {{ Str::plural('employer', $total) }} have an industry.
    This is what the industry figures on a qualification profile are counted from. A suggestion from the name is only pre-selected; nothing is saved until you press Save.
  </p>
  <form class="filters" method="GET" action="{{ route('employers.index') }}" style="margin-top:12px">
    <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Search employers" aria-label="Search employers" maxlength="80">
    <select name="show" aria-label="Which employers">
      <option value="unassigned" @selected($filters['show'] === 'unassigned')>Without an industry</option>
      <option value="all" @selected($filters['show'] === 'all')>All employers</option>
    </select>
    <button class="btn" type="submit">Apply</button>
  </form>

  @if ($employers->isEmpty())
    <p class="empty">{{ $filters['show'] === 'unassigned' && $filters['q'] === '' ? 'Every employer has an industry.' : 'No employer matches.' }}</p>
  @else
    <div class="tw">
      <table class="tbl">
        <thead><tr><th>Employer</th><th>Vacancies</th><th>Industry</th></tr></thead>
        <tbody>
        @foreach ($employers as $e)
          @php $current = $e->industry_id ?? ($suggestions[$e->id] ?? null); @endphp
          <tr>
            <td><b>{{ $e->name }}</b></td>
            <td class="nowrap">{{ $e->vacancies }}</td>
            <td>
              <form method="POST" action="{{ route('employers.update', $e->id) }}" class="btns">
                @csrf @method('PUT')
                <select name="industry_id" aria-label="Industry of {{ $e->name }}">
                  <option value="">None</option>
                  @foreach ($industries as $id => $name)<option value="{{ $id }}" @selected($current !== null && (int) $current === (int) $id)>{{ $name }}</option>@endforeach
                </select>
                <button class="btn primary sm" type="submit">Save</button>
                @if ($e->industry_id === null && isset($suggestions[$e->id]))<small>Suggested from the name</small>@endif
              </form>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    <div style="margin-top:14px">{{ $employers->links() }}</div>
  @endif
</section>
@endsection
