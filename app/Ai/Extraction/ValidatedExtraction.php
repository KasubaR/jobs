<?php

namespace App\Ai\Extraction;

/** An AI answer after checking: what held up, what was rejected and why, and what was corrected. */
final class ValidatedExtraction
{
    /**
     * @param array<string, mixed> $data the cleaned answer, in the schema's shape
     * @param list<array{section:string, reason:string, item:mixed}> $rejected
     * @param list<string> $corrections
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $error,
        public readonly array $data,
        public readonly array $rejected = [],
        public readonly array $corrections = [],
    ) {}

    public static function failed(string $error): self
    {
        return new self(false, $error, []);
    }

    /** Items a person should look at before they count. */
    public function needsReview(): bool
    {
        foreach (QualificationSchema::SECTIONS as $section) {
            foreach ($this->data[$section] ?? [] as $item) {
                if ($item['needs_review']) {
                    return true;
                }
            }
        }

        return $this->rejected !== [] || $this->corrections !== [];
    }

    /** All kept items of one section. */
    public function items(string $section): array
    {
        return $this->data[$section] ?? [];
    }
}
