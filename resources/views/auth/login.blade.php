@extends('layouts.auth')
@section('title', 'Sign in')
@section('content')
<h1>Welcome back</h1>
<p class="sub">Sign in to your private workspace.</p>

@if ($errors->any())
  <div class="alert bad" role="alert">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ route('login.store') }}" novalidate>
  @csrf
  <div @class(['field', 'has-err' => $errors->any()])>
    <label for="email">Email</label>
    <div class="input">
      <svg class="i" aria-hidden="true"><use href="#mail"/></svg>
      <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" placeholder="you@example.com" required autofocus @if ($errors->any()) aria-invalid="true" @endif>
    </div>
  </div>
  <div @class(['field', 'has-err' => $errors->any()])>
    <label for="password">Password</label>
    <div class="input has-toggle">
      <svg class="i" aria-hidden="true"><use href="#lock"/></svg>
      <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Your password" required>
      <button class="reveal" type="button" aria-pressed="false" aria-controls="password" aria-label="Show password">
        <svg class="i on" aria-hidden="true"><use href="#eye"/></svg><svg class="i off" aria-hidden="true"><use href="#eyeoff"/></svg>
      </button>
    </div>
  </div>
  <button class="btn primary" type="submit">Sign in</button>
</form>

<div class="auth-note"><svg class="i" aria-hidden="true"><use href="#shield"/></svg><span>If you have turned on two-factor sign-in, you will be asked for the 6-digit code from your authenticator app next.</span></div>
@endsection
