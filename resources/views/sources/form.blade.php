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
    <div class="field full"><label class="check"><input type="hidden" name="ai_allowed" value="0"><input type="checkbox" name="ai_allowed" value="1" @checked(old('ai_allowed', $source->ai_allowed ?? false))> Allow an AI service to read this source's ads</label><span class="help">Off by default. The ad text (emails already removed) is sent to the AI provider, so switch it on only for a source whose terms allow that. It does nothing for a source that keeps no text.</span></div>
    <div class="field full"><label class="check"><input type="hidden" name="retain_raw" value="0"><input type="checkbox" name="retain_raw" value="1" @checked(old('retain_raw', $source->retain_raw ?? true))> Keep raw ad text for 90 days</label><span class="help">Turn off if the site refuses retention. Only the extracted fields are then kept.</span></div>
    <div class="field full"><label class="check"><input type="hidden" name="lists_all_open_ads" value="0"><input type="checkbox" name="lists_all_open_ads" value="1" @checked(old('lists_all_open_ads', $source->lists_all_open_ads ?? false))> One run lists every ad that is currently open</label><span class="help">Leave off unless that is true. Only then can an ad missing from two successful runs in a row be marked closed. A feed that shows just the latest few ads, or a run limited to the first pages, must stay off, because an ad missing from it is not necessarily closed.</span></div>
  </div>
  </fieldset>

  <fieldset class="fs" data-for-collector="html_listing" @if (old('collector', $source->collector) !== 'html_listing') hidden @endif><legend>Public HTML pages</legend>
    <p class="sub" style="margin-bottom:12px">How to find the ads on this website. CSS selectors only, no code. Nothing is collected until the source is approved and active.</p>
    <div class="fgrid">
      @foreach ([['item', 'Ad on the listing page (selector)', 'e.g. li.job-card'], ['link', 'Link inside the ad (selector)', 'default: a'], ['title', 'Title inside the ad (selector)', 'empty = use the link text'], ['next', '"Next page" link (selector)', 'needed for more than one page'], ['content', 'Full ad text on the ad page (selector)', 'used when opening ad pages'], ['sitemap', 'Sitemap address (optional)', 'e.g. https://example.com/sitemap.xml. Finds ads there, newest first, instead of on listing pages. Then the ad selector, link and next-page settings are not used, and "Open each new ad\'s own page" must be on.'], ['sitemap_match', 'Ad address pattern (with a sitemap)', 'e.g. /jobs/#* where * is anything and # is one digit. Keeps only ad pages and skips category and company pages.']] as [$key, $label, $help])
        <div class="field"><label for="html_{{ $key }}">{{ $label }}</label><input id="html_{{ $key }}" name="html[{{ $key }}]" type="text" maxlength="300" value="{{ old('html.'.$key, $html[$key] ?? '') }}"><span class="help">{{ $help }}</span></div>
      @endforeach
      <div class="field"><label class="check"><input type="hidden" name="html[fetch_detail]" value="0"><input type="checkbox" name="html[fetch_detail]" value="1" @checked(old('html.fetch_detail', $html['fetch_detail'] ?? false))> Open each new ad's own page</label><span class="help">One extra request per new ad, up to the limit below.</span></div>
      <div class="field"><label class="check"><input type="hidden" name="html[structured_data]" value="0"><input type="checkbox" name="html[structured_data]" value="1" @checked(old('html.structured_data', $html['structured_data'] ?? false))> Also read the job data the ad page publishes</label><span class="help">Many sites include machine-readable job data (title, employer, place, dates, pay) for search engines. Facts only, no contact details. Needs "Open each new ad's own page".</span></div>
      <div class="field"><label for="html_max_pages">Listing pages per run</label><input id="html_max_pages" name="html[max_pages]" type="number" min="1" max="{{ \App\Collectors\Html\HtmlConfig::MAX_PAGES }}" value="{{ old('html.max_pages', $html['max_pages']) }}"></div>
      <div class="field"><label for="html_max_items">Ads per run</label><input id="html_max_items" name="html[max_items]" type="number" min="1" max="{{ \App\Collectors\Html\HtmlConfig::MAX_ITEMS }}" value="{{ old('html.max_items', $html['max_items']) }}"></div>
      <div class="field"><label for="html_max_detail_requests">Ad pages opened per run</label><input id="html_max_detail_requests" name="html[max_detail_requests]" type="number" min="0" max="{{ \App\Collectors\Html\HtmlConfig::MAX_DETAIL_REQUESTS }}" value="{{ old('html.max_detail_requests', $html['max_detail_requests']) }}"></div>
    </div>
  </fieldset>

  <fieldset class="fs" data-for-collector="api_json" @if (old('collector', $source->collector) !== 'api_json') hidden @endif><legend>Public API</legend>
    <p class="sub" style="margin-bottom:12px">For a site that offers an official JSON API. Put the API address in "Job listing URL" above, then say which fields hold each ad's title and link. Field names are paths into the JSON, joined by dots (for example <code>data.title</code>). Nothing is collected until the source is approved and active.</p>
    @php $api = array_merge(\App\Collectors\Api\ApiConfig::DEFAULTS, $cfg['api'] ?? []); @endphp
    <div class="fgrid">
      @foreach ([
        ['items_path', 'Where the list of ads is', 'e.g. data.jobs. Empty = the response is the list itself.'],
        ['title', 'Title field', 'e.g. title (required)'],
        ['url', 'Link field', 'e.g. url. Or use a link template below.'],
        ['url_template', 'Link template', 'e.g. https://example.com/jobs/{id}. Needs the ID field.'],
        ['id', 'ID field', 'e.g. id (optional)'],
        ['content', 'Ad text field', 'e.g. description. Empty = the title is stored.'],
        ['employer', 'Employer field', 'optional fact'],
        ['location', 'Location field', 'optional fact'],
        ['date_posted', 'Date posted field', 'optional fact'],
        ['valid_through', 'Closing date field', 'optional fact'],
        ['employment_type', 'Job type field', 'optional fact'],
        ['page_param', 'Page parameter', 'e.g. page. Needed for more than one page.'],
      ] as [$key, $label, $help])
        <div class="field"><label for="api_{{ $key }}">{{ $label }}</label><input id="api_{{ $key }}" name="api[{{ $key }}]" type="text" maxlength="300" value="{{ old('api.'.$key, $api[$key] ?? '') }}"><span class="help">{{ $help }}</span></div>
      @endforeach
      <div class="field"><label for="api_max_pages">Pages per run</label><input id="api_max_pages" name="api[max_pages]" type="number" min="1" max="{{ \App\Collectors\Api\ApiConfig::MAX_PAGES }}" value="{{ old('api.max_pages', $api['max_pages']) }}"></div>
      <div class="field"><label for="api_max_items">Ads per run</label><input id="api_max_items" name="api[max_items]" type="number" min="1" max="{{ \App\Collectors\Api\ApiConfig::MAX_ITEMS }}" value="{{ old('api.max_items', $api['max_items']) }}"></div>
      <div class="field"><label for="api_key_name">API key name</label><input id="api_key_name" name="api[key_name]" type="text" maxlength="60" placeholder="COLLECTOR_KEY_EXAMPLE" value="{{ old('api.key_name', $api['key_name'] ?? '') }}"><span class="help">Only the NAME of the setting. Put the key itself in the server's .env file as <code>COLLECTOR_KEY_...=the-key</code>. Never type the key here. Needs an https:// address.</span></div>
      <div class="field"><label for="api_key_header">Key header</label><input id="api_key_header" name="api[key_header]" type="text" maxlength="40" value="{{ old('api.key_header', $api['key_header']) }}"><span class="help">Authorization sends "Bearer &lt;key&gt;". Another name, such as X-API-Key, sends the key as is.</span></div>
    </div>
    @error('api')<span class="err">{{ $message }}</span>@enderror
  </fieldset>

  <fieldset class="fs" data-for-collector="python_worker"@if (old('collector', $source->collector) !== 'python_worker') hidden @endif><legend>Python worker</legend>
    <p class="sub" style="margin-bottom:12px">For pages that need real parsing or a real browser. The app does not fetch this source. A Python program on your own computer (<code>collectors_py/run_due.py</code>) collects it and hands the ads over; the app then schedules, logs and backs off as for any source. Set the collection method to "Python worker".</p>
    @php $py = $cfg['python'] ?? []; @endphp
    <div class="fgrid">
      <div class="field"><label for="python_script">Script</label><input id="python_script" name="python[script]" type="text" maxlength="60" placeholder="example_bs4" value="{{ old('python.script', $py['script'] ?? '') }}"><span class="help">The name of a file in <code>collectors_py/scripts</code>, without .py. Lowercase letters, digits and underscores.</span></div>
      <div class="field"><label for="python_options">Options (JSON, optional)</label><textarea id="python_options" name="python[options]" maxlength="2000" style="min-height:70px" placeholder='{"max_pages": 3}'>{{ old('python.options', ! empty($py['options']) ? json_encode($py['options'], JSON_UNESCAPED_SLASHES) : '') }}</textarea><span class="help">Passed to the script. Keep it to settings such as page limits or selectors, never passwords or keys.</span></div>
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
