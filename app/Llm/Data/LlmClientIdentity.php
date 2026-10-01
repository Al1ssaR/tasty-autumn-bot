<?php

namespace App\Llm\Data;

final readonly class LlmClientIdentity
{
    public function __construct(
        public ?string $provider,
        public ?string $model,
    ) {}
}
