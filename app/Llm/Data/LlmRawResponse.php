<?php

namespace App\Llm\Data;

final readonly class LlmRawResponse
{
    public function __construct(public string $content) {}
}
