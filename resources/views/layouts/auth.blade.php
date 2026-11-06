<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>@yield('title') · Zamtrends</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/admin.css') }}">
</head>
<body class="auth">
@include('partials.icons')
<section class="auth-hero" aria-label="About this app">
  <div class="auth-brand">
    <span class="mark"><svg class="i"><use href="#bars"/></svg></span>
    <div>Zamtrends<small>Zambia labour market</small></div>
  </div>
  <div>
    <h2>The Zambian job market, in one private place.</h2>
    <p>Vacancies are collected only from sources you have approved, then organised so you can see what employers are asking for.</p>
    <ul class="auth-points">
      <li><span class="ic"><svg class="i"><use href="#shield"/></svg></span><div><b>Approved sources only</b><span>Nothing is collected until you decide a site is allowed.</span></div></li>
      <li><span class="ic"><svg class="i"><use href="#globe"/></svg></span><div><b>Every source in one list</b><span>Feeds, public pages and ads you add by hand.</span></div></li>
      <li><span class="ic"><svg class="i"><use href="#lock"/></svg></span><div><b>Private by design</b><span>One person, a password, and optional two-factor sign-in.</span></div></li>
    </ul>
  </div>
  <div class="auth-foot">Not listed in search engines.</div>
</section>
<main class="auth-panel">
  <button class="theme" id="theme" type="button" aria-label="Toggle light/dark mode"><svg class="i sun"><use href="#sun"/></svg><svg class="i moon"><use href="#moon"/></svg></button>
  <div class="authbox">
    @yield('content')
  </div>
</main>
<script src="{{ asset('js/admin.js') }}"></script>
</body>
</html>
