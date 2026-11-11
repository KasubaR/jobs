@extends('layouts.app')
@section('title', $title)
@section('heading', $title)
@section('subheading', 'Every row, counting each vacancy once')
@section('content')
@php
    $pct = fn (int $n, int $of) => $of > 0 ? round($n / $of * 100) : 0;
    $max = (int) collect($rows)->max('n');
@endphp
<section class="card">
  <div class="hd">
    <div><h3>{{ $title }}</h3><p class="sub">{{ $sub }}</p></div>
    <a class="btn sm" href="{{ route('qualifications.index', array_filter($filters)) }}">Back to Qualifications</a>
  </div>
  @forelse ($rows as $i => $row)
    <div class="rm"><div class="h"><b>{{ $row['name'] }}</b><span>{{ $row['text'] ?? $pct($row['n'], $of).'%' }}</span><em>{{ number_format($row['n']) }}</em></div>
      <div class="bar"><i class="{{ $i === 0 ? 'hi' : '' }}" style="width:{{ max(3, $pct($row['n'], $max)) }}%"></i></div></div>
  @empty
    <p class="empty">Nothing has been read for this yet.</p>
  @endforelse
</section>
@endsection
