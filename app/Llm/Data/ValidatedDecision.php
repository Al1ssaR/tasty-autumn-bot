<?php

namespace App\Llm\Data;

use App\Llm\Enums\DecisionAction;
use App\Llm\Enums\DecisionReason;

final readonly class ValidatedDecision
{
    /** @param list<string> $ruleReferences */
    public function __construct(
        public DecisionAction $action,
        public DecisionReason $reason,
        public string $answer,
        public array $ruleReferences,
    ) {}
}
