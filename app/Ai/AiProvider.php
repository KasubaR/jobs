<?php

namespace App\Ai;

/** A model service behind one small interface, so the provider or model can change without touching the callers. */
interface AiProvider
{
    public function name(): string;

    /** The model in use, or null when none is set. */
    public function model(): ?string;

    /** Whether it has what it needs (a key and a model). */
    public function configured(): bool;

    /**
     * One request. Returns the model's text and the tokens used.
     *
     * @throws AiFailure when the request cannot be completed; the exception says whether trying again could help
     */
    public function generate(string $system, string $prompt, bool $json = true): AiResponse;
}
