@extends('layouts.auth')
@section('title', 'Verification code')
@section('content')
<h1>Verification code</h1>
<p class="sub">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
<form method="POST" action="{{ route('two-factor.verify') }}" novalidate>
  @csrf
  <div @class(['field', 'has-err' => $errors->has('code')])>
    <label for="code">Code</label>
    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="32" required autofocus>
    @error('code')<span class="err" role="alert">{{ $message }}</span>@enderror
  </div>
  <button class="btn primary" type="submit">Continue</button>
</form>
<form method="POST" action="{{ route('logout') }}" style="margin-top:12px">@csrf<button class="btn" type="submit" style="width:100%;justify-content:center">Cancel and sign out</button></form>
@endsection
