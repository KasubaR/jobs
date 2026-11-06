<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>@yield('title', 'Dashboard') · Job Intel</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/admin.css') }}">
</head>
<body>
@include('partials.icons')
@php
    $nav = [
        ['dashboard', 'Dashboard', 'dash', 'dashboard'],
        ['sources.index', 'Sources', 'globe', 'sources.*'],
        ['runs.index', 'Collection runs', 'clock', 'runs.*'],
    ];
    $initial = strtoupper(substr(auth()->user()->name ?: auth()->user()->email, 0, 1));
@endphp
<div class="app">
<aside id="side">
  <div class="brand"><div>Job Intel<small>Zambia labour market</small></div><button id="close" aria-label="Close menu" type="button">&times;</button></div>
  <nav aria-label="Main">
    @foreach ($nav as [$route, $label, $icon, $match])
      <a href="{{ route($route) }}" @class(['on' => request()->routeIs($match)]) @if (request()->routeIs($match)) aria-current="page" @endif><svg class="i"><use href="#{{ $icon }}"/></svg>{{ $label }}</a>
    @endforeach
  </nav>
  <div class="me">
    <div class="ava">{{ $initial }}</div>
    <div style="min-width:0"><b>{{ auth()->user()->name ?: 'Administrator' }}</b><span>{{ auth()->user()->email }}</span></div>
    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form>
  </div>
</aside>
<div style="min-width:0">
<header class="top">
  <button class="menu" id="menu" type="button" aria-label="Open menu"><svg class="i"><use href="#grid"/></svg></button>
  <div class="t"><h1>@yield('heading', 'Dashboard')</h1><p>@yield('subheading')</p></div>
  <form class="search" method="GET" action="{{ route('sources.index') }}" role="search">
    <svg class="i"><use href="#find"/></svg>
    <input name="q" value="{{ request('q') }}" placeholder="Search sources…" aria-label="Search sources" maxlength="100">
  </form>
  <button class="theme" id="theme" type="button" aria-label="Toggle light/dark mode"><svg class="i sun"><use href="#sun"/></svg><svg class="i moon"><use href="#moon"/></svg></button>
  <div class="ava b" aria-hidden="true">{{ $initial }}</div>
</header>
<main>
  @include('partials.flash')
  @yield('content')
</main>
</div>
</div>
<script src="{{ asset('js/admin.js') }}"></script>
</body>
</html>
