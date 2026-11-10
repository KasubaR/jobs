<?php

namespace App\Ai;

/** What a provider returned. */
final class AiResponse
{
    public function __construct(public readonly string $text, public readonly ?int $inputTokens, public readonly ?int $outputTokens) {}

    /** The text read as JSON, or null when it is not valid JSON. */
    public function json(): ?array
    {
        $data = json_decode($this->text, true);

        return is_array($data) ? $data : null;
    }
}
