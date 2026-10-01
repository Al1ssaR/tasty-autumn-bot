<?php

namespace App\Llm\Data;

final readonly class PromptAssets
{
    /** @param array<string, mixed> $responseSchema */
    public function __construct(
        public string $systemPrompt,
        public string $rules,
        public array $responseSchema,
        public string $promptVersion,
        public string $rulesHash,
    ) {}
}
