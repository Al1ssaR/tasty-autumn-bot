<?php

namespace App\Llm\Data;

final readonly class LlmDecisionRequest
{
    /** @param array<string, mixed> $responseSchema */
    public function __construct(
        public string $systemPrompt,
        public string $rules,
        public string $currentTime,
        public string $userMessage,
        public array $responseSchema,
        public string $promptVersion,
        public string $rulesHash,
    ) {}
}
