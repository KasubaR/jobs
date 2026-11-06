@extends('layouts.app')
@section('title', 'Security')
@section('heading', 'Security')
@section('subheading', 'How you sign in')
@section('content')
<section class="card" style="max-width:720px">
  <div class="hd">
    <div><h3>Two-factor sign-in</h3><p class="sub">An extra 6-digit code from an authenticator app after your password.</p></div>
    <span @class(['pill', 'mut' => ! $enabled])>{{ $enabled ? 'On' : 'Off' }}</span>
  </div>

  @if (! $enabled)
    <p>Two-factor is <b>off</b>, so your password is all that is needed to sign in. Turning it on protects the app if your password is ever guessed or leaked. You can turn it off again at any time.</p>
    <div class="btns" style="margin-top:16px"><a class="btn primary" href="{{ route('two-factor.setup') }}"><svg class="i"><use href="#shield"/></svg>Turn on two-factor</a></div>
  @else
    <p>Two-factor is <b>on</b>. You are asked for the code from your authenticator app each time you sign in. You have <b>{{ $recoveryLeft }}</b> unused recovery {{ Str::plural('code', $recoveryLeft) }}.</p>

    <form method="POST" action="{{ route('security.recovery') }}" style="margin-top:22px">
      @csrf
      <h3 style="font-size:15px;margin-bottom:6px">New recovery codes</h3>
      <p class="sub" style="margin-bottom:10px">Replaces your old codes. The new ones are shown once.</p>
      <div class="fgrid">
        <div @class(['field', 'has-err' => $errors->has('current_password') && old('_form') === 'recovery'])><label for="rc_pw">Your password</label><input id="rc_pw" name="current_password" type="password" autocomplete="current-password" required><input type="hidden" name="_form" value="recovery"></div>
        <div class="field" style="justify-content:flex-end"><button class="btn" type="submit">Get new recovery codes</button></div>
      </div>
    </form>

    <form method="POST" action="{{ route('security.disable') }}" style="margin-top:22px" data-confirm="Turn off two-factor sign-in? Sign-in will need only your password.">
      @csrf
      <h3 style="font-size:15px;margin-bottom:6px">Turn off</h3>
      <p class="sub" style="margin-bottom:10px">Sign-in will need only your password.</p>
      <div class="fgrid">
        <div class="field"><label for="off_pw">Your password</label><input id="off_pw" name="current_password" type="password" autocomplete="current-password" required></div>
        <div class="field" style="justify-content:flex-end"><button class="btn danger" type="submit">Turn off two-factor</button></div>
      </div>
    </form>
  @endif
</section>
@endsection
