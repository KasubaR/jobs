@extends('layouts.auth')
@section('title', 'Turn on two-factor sign-in')
@section('content')
<h1>Turn on two-factor sign-in</h1>
<p class="sub">After this, a password alone cannot open the app. Scan the code with an authenticator app (Google Authenticator, Microsoft Authenticator, Aegis…), then type the 6-digit code it shows.</p>
<div class="qr" role="img" aria-label="QR code to scan with your authenticator app">{!! $qr !!}</div>
<p class="mut" style="font-size:12px;margin:0 0 6px">Can't scan? Enter this key by hand:</p>
<code class="secret">{{ $secret }}</code>
<form method="POST" action="{{ route('two-factor.confirm') }}" novalidate style="margin-top:16px">
  @csrf
  <div @class(['field', 'has-err' => $errors->has('code')])>
    <label for="code">6-digit code</label>
    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="16" required autofocus>
    @error('code')<span class="err" role="alert">{{ $message }}</span>@enderror
  </div>
  <div @class(['field', 'has-err' => $errors->has('current_password')])>
    <label for="current_password">Your password</label>
    <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
    <span class="help">Asked so nobody with just an open session can lock you out.</span>
    @error('current_password')<span class="err" role="alert">{{ $message }}</span>@enderror
  </div>
  <button class="btn primary" type="submit">Turn on and continue</button>
</form>
<a class="btn" href="{{ route('security') }}" style="margin-top:10px">Cancel</a>
@endsection
