<?php

namespace App\Ai\Extraction;

use App\Ai\AiClient;
use App\Ai\AiFailure;

/**
 * Asks the AI model what one advert asks of an applicant and checks the answer. It prepares the prompt (the schema's rules
 * and the fenced advert text), sends it through AiClient (which holds the key, limits, retries and usage record), and
 * validates the reply against the advert. It writes nothing; AiRequirementApplier stores what held up.
 *
 * It knows nothing about Gemini. Another provider is a new AiProvider and a line in AppServiceProvider.
 */
class QualificationExtractor
{
    public function __construct(private AiClient $ai, private ExtractionValidator $validator) {}

    /**
     * @param string $adText the advert as plain text (what the validator checks the answer's quotes against)
     *
     * @throws AiFailure when the request could not be made or completed
     */
    public function extract(string $adText, ?int $jobId = null, string $purpose = 'extract'): ValidatedExtraction
    {
        $reply = $this->ai->ask(QualificationSchema::instructions(), QualificationSchema::prompt($adText), $purpose, $jobId, promptVersion: QualificationSchema::PROMPT_VERSION);

        $data = $reply->json();

        return $data === null
            ? ValidatedExtraction::failed('The answer is not valid JSON.')
            : $this->validator->validate($data, $adText);
    }
}
