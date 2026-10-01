<?php

namespace App\Llm\Contracts;

use App\Llm\Data\LlmClientIdentity;
use App\Llm\Data\LlmDecisionRequest;
use App\Llm\Data\LlmRawResponse;

interface LlmDecisionClient
{
    public function identity(): LlmClientIdentity;

    public function decide(LlmDecisionRequest $request): LlmRawResponse;
}
