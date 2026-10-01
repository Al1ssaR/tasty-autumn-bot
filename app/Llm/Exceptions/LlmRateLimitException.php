<?php

namespace App\Llm\Exceptions;

final class LlmRateLimitException extends LlmApiException
{
    public function __construct(public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct('LLM provider rate limit exceeded.');
    }
}
