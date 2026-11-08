<?php

namespace App\Http\Requests;

use App\Collectors\Api\ApiConfig;
use App\Collectors\CollectorRegistry;
use App\Collectors\Html\HtmlConfig;
use App\Extraction\ExtractionRules;
use App\Models\Source;
use App\Support\SourceFormData;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add / edit a source. Deliberately does NOT accept access_status or is_active:
 * those change only through the logged "Decide access" and "Activate" actions.
 */
class SourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route middleware already limits this to the administrator
    }

    public function rules(): array
    {
        $source = $this->route('source');

        return [
            'name' => ['required', 'string', 'max:160'],
            'source_type' => ['required', Rule::in(array_keys(Source::SOURCE_TYPES))],
            'website_url' => ['required', 'url:http,https', 'max:255', Rule::unique('sources', 'website_url')->ignore($source?->id)],
            'job_listing_url' => ['nullable', 'url:http,https', 'max:255'],
            'collection_method' => ['required', Rule::in(array_keys(Source::METHODS))],
            'collector' => ['nullable', Rule::in(array_keys(CollectorRegistry::COLLECTORS))],
            'collection_frequency' => ['required', Rule::in(array_keys(Source::FREQUENCIES))],
            'min_interval_seconds' => ['required', 'integer', 'min:1', 'max:3600'],
            'retain_raw' => ['sometimes', 'boolean'],
            'lists_all_open_ads' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->input('collector') === 'html_listing') {
                foreach (HtmlConfig::validate(SourceFormData::html($this->all())) as $problem) {
                    $validator->errors()->add('html', $problem);
                }
            }

            if ($this->input('collector') === 'api_json') {
                $api = SourceFormData::api($this->all());

                foreach (ApiConfig::validate($api) as $problem) {
                    $validator->errors()->add('api', $problem);
                }
                if ($this->input('collection_method') !== 'api') {
                    $validator->errors()->add('collection_method', 'The API collector needs the collection method "Public API".');
                }
                if (blank($this->input('job_listing_url'))) {
                    $validator->errors()->add('job_listing_url', 'Enter the API address (the endpoint that lists the ads).');
                } elseif (isset($api['key_name']) && ! str_starts_with(strtolower((string) $this->input('job_listing_url')), 'https://')) {
                    $validator->errors()->add('job_listing_url', 'A source that uses an API key must use an https:// address, so the key is never sent unprotected.');
                }
            }

            if ($this->input('collector') === 'python_worker') {
                $python = SourceFormData::python($this->all());

                if (! preg_match('/^[a-z][a-z0-9_]{0,59}$/', $python['script'])) {
                    $validator->errors()->add('python', 'The script name must be lowercase letters, digits and underscores (for example example_bs4): the name of a file in collectors_py/scripts.');
                }
                if ($python['optionsError']) {
                    $validator->errors()->add('python', $python['optionsError']);
                }
                if ($this->input('collection_method') !== 'python') {
                    $validator->errors()->add('collection_method', 'The Python worker collector needs the collection method "Python worker".');
                }
            }

            foreach (ExtractionRules::validate(SourceFormData::extraction($this->all())) as $problem) {
                $validator->errors()->add('extraction', $problem);
            }
        }];
    }

    /** Fields that may be written to the source. */
    public function sourceData(): array
    {
        $data = $this->validated();

        return [
            'name' => $data['name'],
            'source_type' => $data['source_type'],
            'website_url' => $data['website_url'],
            'job_listing_url' => $data['job_listing_url'] ?? null,
            'collection_method' => $data['collection_method'],
            'collector' => $data['collector'] ?? null,
            'collection_frequency' => $data['collection_frequency'],
            'min_interval_seconds' => (int) $data['min_interval_seconds'],
            'retain_raw' => $this->boolean('retain_raw'),
            'lists_all_open_ads' => $this->boolean('lists_all_open_ads'),
            'notes' => $data['notes'] ?? null,
            'collector_config' => SourceFormData::config($this->all(), $data['collector'] ?? null),
        ];
    }
}
