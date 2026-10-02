<?php

namespace App\Llm;

use App\Llm\Contracts\LlmDecisionClient;
use App\Llm\Data\LlmClientIdentity;
use App\Llm\Data\LlmDecisionRequest;
use App\Llm\Data\LlmRawResponse;
use App\Llm\Exceptions\LlmApiException;

final class UnavailableLlmDecisionClient implements LlmDecisionClient
{
    public function __construct(private readonly LlmClientIdentity $identity) {}

    public function identity(): LlmClientIdentity
    {
        return $this->identity;
    }

    public function decide(LlmDecisionRequest $request): LlmRawResponse
    {
        throw new LlmApiException('No LLM provider client is configured.');
    }
}
