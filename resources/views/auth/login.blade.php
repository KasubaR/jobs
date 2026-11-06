@extends('layouts.auth')
@section('title', 'Sign in')
@section('content')
<h1>Job Intel</h1>
<p class="sub">Private. Sign in to continue.</p>
<form method="POST" action="{{ route('login.store') }}" novalidate>
  @csrf
  <div @class(['field', 'has-err' => $errors->has('email')])>
    <label for="email">Email</label>
    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
    @error('email')<span class="err" role="alert">{{ $message }}</span>@enderror
  </div>
  <div class="field">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
  </div>
  <button class="btn primary" type="submit">Sign in</button>
</form>
@endsection
