@extends('layouts.app')
@php
    $editing = $source->exists;
    $cfg = $source->collector_config ?? [];
    $old = fn ($key, $default = null) => old($key, $default);
    $html = array_merge(\App\Collectors\Html\HtmlConfig::DEFAULTS, $cfg['html'] ?? []);
    $ex = $cfg['extraction'] ?? [];
    $rules = old('extraction.fields', $ex['fields'] ?? []);
    $formats = old('extraction.date_formats', implode(', ', $ex['date_formats'] ?? []));
    $err = fn ($key) => $errors->first($key);
@endphp
@section('title', $editing ? 'Edit '.$source->name : 'Add source')
@section('heading', $editing ? 'Edit source' : 'Add source')
@section('subheading', $editing ? $source->name : 'Register a website to collect ads from')
@section('content')
<form id="source-form" method="POST" action="{{ $editing ? route('sources.update', $source) : route('sources.store') }}" novalidate
  @unless ($editing) data-suggestions='@json(\App\Models\Source::SUGGESTED_FREQUENCY)' data-frequency-labels='@json(\App\Models\Source::FREQUENCIES)' @endunless>
  @csrf
  @if ($editing) @method('PUT') @endif

  <p class="sub" style="margin-bottom:16px">Access status and the on/off switch are not edited here. Use "Decide access" and "Activate" on the source page, so every change is recorded.</p>

  <fieldset class="fs"><legend>Source</legend>
  <div class="fgrid">
    <div @class(['field', 'has-err' => $errors->has('name')])><label for="name">Name</label><input id="name" name="name" type="text" maxlength="160" required value="{{ old('name', $source->name) }}">@error('name')<span class="err">{{ $message }}</span>@enderror</div>
    <div @class(['field', 'has-err' => $errors->has('source_type')])><label for="source_type">Type</label>
      <select id="source_type" name="source_type" required><option value="">Choose…</option>
      @foreach (\App\Models\Source::SOURCE_TYPES as $k => $v)<option value="{{ $k }}" @selected(old('source_type', $source->source_type) === $k)>{{ $v }}</option>@endforeach</select>@error('source_type')<span class="err">{{ $message }}</span>@enderror</div>
    <div @class(['field', 'has-err' => $errors->has('website_url')])><label for="website_url">Website URL</label><input id="website_url" name="website_url" type="url" maxlength="255" required value="{{ old('website_url', $source->website_url) }}">@error('website_url')<span class="err">{{ $message }}</span>@enderror</div>
    <div @class(['field', 'has-err' => $errors->has('job_listing_url')])><label for="job_listing_url">Job listing URL</label><input id="job_listing_url" name="job_listing_url" type="url" maxlength="255" value="{{ old('job_listing_url', $source->job_listing_url) }}"><span class="help">The feed or page where vacancies appear. Not needed for manual sources.</span>@error('job_listing_url')<span class="err">{{ $message }}</span>@enderror</div>
  </div>
  </fieldset>

  <fieldset class="fs"><legend>Collection</legend>
  <div class="fgrid">
    <div @class(['field', 'has-err' => $errors->has('collection_method')])><label for="collection_method">Method</label>
      <select id="collection_method" name="collection_method" required>@foreach (\App\Models\Source::METHODS as $k => $v)<option value="{{ $k }}" @selected(old('collection_method', $source->collection_method) === $k)>{{ $v }}</option>@endforeach</select>@error('collection_method')<span class="err">{{ $message }}</span>@enderror</div>
    <div @class(['field', 'has-err' => $errors->has('collector')])><label for="collector">Collector</label>
      <select id="collector" name="collector"><option value="">None (cannot be collected yet)</option>@foreach ($collectors as $key)<option value="{{ $key }}" @selected(old('collector', $source->collector) === $key)>{{ $key }}</option>@endforeach</select>
      <span class="help">The code that knows this website. A new website needs a collector written for it.</span>@error('collector')<span class="err">{{ $message }}</span>@enderror</div>
    <div @class(['field', 'has-err' => $errors->has('collection_frequency')])><label for="collection_frequency">Frequency</label>
      <select id="collection_frequency" name="collection_frequency" required>@foreach (\App\Models\Source::FREQUENCIES as $k => $v)<option value="{{ $k }}" @selected(old('collection_frequency', $source->collection_frequency) === $k)>{{ $v }}</option>@endforeach</select>
      <span class="help" id="frequency-hint">Busy job boards: every 6 hours. Career and government pages: daily. Rarely updated sources: every 2–3 days. It is only a suggestion.</span>@error('collection_frequency')<span class="err">{{ $message }}</span>@enderror</div>
    <div @class(['field', 'has-err' => $errors->has('min_interval_seconds')])><label for="min_interval_seconds">Minimum seconds between requests</label><input id="min_interval_seconds" name="min_interval_seconds" type="number" min="1" max="3600" required value="{{ old('min_interval_seconds', $source->min_interval_seconds ?? 1) }}"><span class="help">Never lower than the site's own Crawl-delay.</span>@error('min_interval_seconds')<span class="err">{{ $message }}</span>@enderror</div>
    <div class="field full"><label class="check"><input type="hidden" name="retain_raw" value="0"><input type="checkbox" name="retain_raw" value="1" @checked(old('retain_raw', $source->retain_raw ?? true))> Keep raw ad text for 90 days</label><span class="help">Turn off if the site refuses retention. Only the extracted fields are then kept.</span></div>
  </div>
  </fieldset>

  <fieldset class="fs" data-for-collector="html_listing" @if (old('collector', $source->collector) !== 'html_listing') hidden @endif><legend>Public HTML pages</legend>
    <p class="sub" style="margin-bottom:12px">How to find the ads on this website. CSS selectors only, no code. Nothing is collected until the source is approved and active.</p>
    <div class="fgrid">
      @foreach ([['item', 'Ad on the listing page (selector)', 'e.g. li.job-card'], ['link', 'Link inside the ad (selector)', 'default: a'], ['title', 'Title inside the ad (selector)', 'empty = use the link text'], ['next', '"Next page" link (selector)', 'needed for more than one page'], ['content', 'Full ad text on the ad page (selector)', 'used when opening ad pages']] as [$key, $label, $help])
        <div class="field"><label for="html_{{ $key }}">{{ $label }}</label><input id="html_{{ $key }}" name="html[{{ $key }}]" type="text" maxlength="200" value="{{ old('html.'.$key, $html[$key] ?? '') }}"><span class="help">{{ $help }}</span></div>
      @endforeach
      <div class="field"><label class="check"><input type="hidden" name="html[fetch_detail]" value="0"><input type="checkbox" name="html[fetch_detail]" value="1" @checked(old('html.fetch_detail', $html['fetch_detail'] ?? false))> Open each new ad's own page</label><span class="help">One extra request per new ad, up to the limit below.</span></div>
      <div class="field"><label for="html_max_pages">Listing pages per run</label><input id="html_max_pages" name="html[max_pages]" type="number" min="1" max="{{ \App\Collectors\Html\HtmlConfig::MAX_PAGES }}" value="{{ old('html.max_pages', $html['max_pages']) }}"></div>
      <div class="field"><label for="html_max_items">Ads per run</label><input id="html_max_items" name="html[max_items]" type="number" min="1" max="{{ \App\Collectors\Html\HtmlConfig::MAX_ITEMS }}" value="{{ old('html.max_items', $html['max_items']) }}"></div>
      <div class="field"><label for="html_max_detail_requests">Ad pages opened per run</label><input id="html_max_detail_requests" name="html[max_detail_requests]" type="number" min="0" max="{{ \App\Collectors\Html\HtmlConfig::MAX_DETAIL_REQUESTS }}" value="{{ old('html.max_detail_requests', $html['max_detail_requests']) }}"></div>
    </div>
  </fieldset>

  <fieldset class="fs"><legend>Extraction rules</legend>
    <p class="sub" style="margin-bottom:12px">How to read this website's ads. Plain patterns, not code. Use "Preview extraction" to try them on stored ads before relying on them.</p>
    <div class="fgrid">
      <div class="field"><label for="title_separator">Split the title into job and employer on</label><input id="title_separator" name="extraction[title_separator]" type="text" maxlength="20" placeholder=" at " value="{{ old('extraction.title_separator', $ex['title_separator'] ?? '') }}"><span class="help">"Budget Analyst at World Vision" splits on " at ". Uses the last occurrence.</span></div>
      <div class="field"><label for="date_formats">Date formats on this site</label><input id="date_formats" name="extraction[date_formats]" type="text" placeholder="j F Y, d/m/Y" value="{{ $formats }}"><span class="help">Separate with commas. "j F Y" reads 5 December 2026, "d/m/Y" reads 05/12/2026.</span></div>
    </div>
    <p style="margin:16px 0 6px;font-weight:600">Patterns</p>
    <p class="help mut" style="font-size:12px;margin:0 0 8px">The first (bracketed) group becomes the value. Example: closing date:\s*(.+)</p>
    <div class="rules" id="rules">
      @foreach ($rules as $i => $rule)
        <div class="rule">
          <select name="extraction[fields][{{ $i }}][field]" aria-label="Field"><option value="">Field…</option>@foreach (\App\Extraction\Extractor::FIELDS as $k => $v)<option value="{{ $k }}" @selected(($rule['field'] ?? '') === $k)>{{ $v }}</option>@endforeach</select>
          <input type="text" name="extraction[fields][{{ $i }}][pattern]" maxlength="200" aria-label="Pattern" value="{{ $rule['pattern'] ?? '' }}">
          <button class="btn sm" type="button" data-remove-rule>Remove</button>
        </div>
      @endforeach
    </div>
    <template id="rule-template">
      <div class="rule">
        <select name="extraction[fields][__i__][field]" aria-label="Field"><option value="">Field…</option>@foreach (\App\Extraction\Extractor::FIELDS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
        <input type="text" name="extraction[fields][__i__][pattern]" maxlength="200" aria-label="Pattern">
        <button class="btn sm" type="button" data-remove-rule>Remove</button>
      </div>
    </template>
    <div class="btns" style="margin-top:10px">
      <button class="btn sm" type="button" id="add-rule"><svg class="i"><use href="#plus"/></svg>Add pattern</button>
      @if ($editing)<button class="btn sm" type="button" id="preview-btn" data-url="{{ route('sources.preview', $source) }}">Preview extraction</button>@endif
    </div>
  </fieldset>

  <fieldset class="fs"><legend>Notes</legend>
    <div class="field"><label for="notes" class="mut" style="font-weight:400">Terms, robots.txt findings, who gave permission…</label><textarea id="notes" name="notes" maxlength="5000">{{ old('notes', $source->notes) }}</textarea>@error('notes')<span class="err">{{ $message }}</span>@enderror</div>
  </fieldset>

  <div class="btns"><button class="btn primary" type="submit">{{ $editing ? 'Save changes' : 'Add source' }}</button><a class="btn" href="{{ $editing ? route('sources.show', $source) : route('sources.index') }}">Cancel</a></div>
</form>

@if ($editing)
<dialog class="dlg wide" id="preview-dlg" aria-labelledby="preview-title">
  <h3 id="preview-title">Extraction preview</h3>
  <div id="preview-out"></div>
  <div class="acts"><button class="btn" type="button" data-close>Close</button></div>
</dialog>
@endif
@endsection
