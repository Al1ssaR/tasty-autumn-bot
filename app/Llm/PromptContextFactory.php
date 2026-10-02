<?php

namespace App\Llm;

use App\Llm\Data\LlmDecisionRequest;
use App\Support\Contracts\Clock;

final class PromptContextFactory
{
    public function __construct(
        private readonly PromptAssetLoader $assets,
        private readonly PiiRedactor $redactor,
        private readonly Clock $clock,
    ) {}

    public function build(string $originalMessage): LlmDecisionRequest
    {
        $assets = $this->assets->load();
        $now = $this->clock->now();
        $timezone = (string) config('bot.business_timezone', 'Europe/Moscow');

        return new LlmDecisionRequest(
            $assets->systemPrompt,
            $assets->rules,
            $now->format('Y-m-d\TH:i:sP')." [{$timezone}]",
            $this->redactor->redact($originalMessage),
            $assets->responseSchema,
            $assets->promptVersion,
            $assets->rulesHash,
        );
    }
}
