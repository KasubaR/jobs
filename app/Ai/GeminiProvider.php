<?php

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Google Gemini through the Google AI Studio API. The key travels in a header, never in the address, so it cannot end up in a log line. */
class GeminiProvider implements AiProvider
{
    public function name(): string
    {
        return 'gemini';
    }

    public function model(): ?string
    {
        return config('job_intel.ai.gemini.model') ?: null;
    }

    public function configured(): bool
    {
        return filled(config('job_intel.ai.gemini.key')) && $this->model() !== null;
    }

    public function generate(string $system, string $prompt, bool $json = true): AiResponse
    {
        $url = rtrim((string) config('job_intel.ai.gemini.base_url'), '/').'/models/'.rawurlencode((string) $this->model()).':generateContent';

        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0] + ($json ? ['responseMimeType' => 'application/json'] : []),
        ];

        try {
            $response = Http::withHeaders(['x-goog-api-key' => (string) config('job_intel.ai.gemini.key')])
                ->timeout((int) config('job_intel.ai.timeout'))->acceptJson()->asJson()->post($url, $body);
        } catch (ConnectionException) {
            throw new AiFailure('The AI service did not answer in time or could not be reached.', retryable: true, kind: 'error');
        }

        $status = $response->status();

        if ($status === 429) {
            throw new AiFailure('The AI service is limiting requests (rate limit or quota).', retryable: true, status: 429, retryAfter: $this->retryAfter($response), kind: 'rate_limited');
        }
        if ($status >= 500) {
            throw new AiFailure("The AI service had an error ({$status}).", retryable: true, status: $status, retryAfter: $this->retryAfter($response));
        }
        if (in_array($status, [401, 403], true)) {
            throw new AiFailure('The AI key was refused. Check GEMINI_API_KEY and that the key is allowed to use this model.', status: $status);
        }
        if ($status >= 400) {
            throw new AiFailure("The AI service rejected the request ({$status}): ".$this->reason($response), status: $status);
        }

        $block = $response->json('promptFeedback.blockReason');
        if ($block) {
            throw new AiFailure("The AI service declined this text ({$block}).", status: $status, kind: 'blocked');
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            throw new AiFailure('The AI service returned no text.', status: $status);
        }

        return new AiResponse($text, $this->count($response->json('usageMetadata.promptTokenCount')), $this->count($response->json('usageMetadata.candidatesTokenCount')));
    }

    private function count(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function retryAfter($response): ?int
    {
        $h = $response->header('Retry-After');

        return is_numeric($h) ? max(0, min(60, (int) $h)) : null;
    }

    /** The service's own short reason, cut to a safe length. */
    private function reason($response): string
    {
        return mb_substr((string) ($response->json('error.message') ?? ''), 0, 120);
    }
}
