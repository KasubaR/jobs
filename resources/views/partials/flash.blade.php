@if (session('status'))
  <div class="alert ok" role="status">{{ session('status') }}@if (session('run_id')) <a href="{{ route('runs.show', session('run_id')) }}">View the run</a>@endif</div>
@endif
@if (session('error'))
  <div class="alert bad" role="alert">{{ session('error') }}@if (session('run_id')) <a href="{{ route('runs.show', session('run_id')) }}">View the run</a>@endif</div>
@endif
@if (session('warning'))
  <div class="alert warn" role="status">{{ session('warning') }}</div>
@endif
@if ($errors->any() && ! ($hideErrors ?? false))
  <div class="alert bad" role="alert">
    <b>Please fix the following:</b>
    <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
  </div>
@endif
