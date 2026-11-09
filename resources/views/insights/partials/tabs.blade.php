<div class="btns" style="margin-bottom:12px">
  <a class="btn sm {{ $active === 'overview' ? 'primary' : '' }}" href="{{ route('insights.index') }}">Overview</a>
  <a class="btn sm {{ $active === 'occupation' ? 'primary' : '' }}" href="{{ route('insights.group', 'occupation') }}">By occupation</a>
  <a class="btn sm {{ $active === 'industry' ? 'primary' : '' }}" href="{{ route('insights.group', 'industry') }}">By industry</a>
  <a class="btn sm {{ $active === 'trends' ? 'primary' : '' }}" href="{{ route('insights.trends') }}">Over time</a>
  <a class="btn sm {{ $active === 'supply' ? 'primary' : '' }}" href="{{ route('insights.supply') }}">Supply and demand</a>
</div>
