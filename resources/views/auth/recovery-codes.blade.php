@extends('layouts.auth')
@section('title', 'Recovery codes')
@section('content')
<h1>Save your recovery codes</h1>
<p class="sub">If you lose your phone, each of these works once instead of an app code. They are shown <b>only now</b>. Store them somewhere safe, such as a password manager.</p>
<div class="codes">
  @foreach ($codes as $code)<span>{{ $code }}</span>@endforeach
</div>
<a class="btn primary" href="{{ route('dashboard') }}">I have saved them</a>
@endsection
