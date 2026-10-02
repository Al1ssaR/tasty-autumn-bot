<?php

namespace App\Llm\Enums;

enum DecisionReason: string
{
    case GroundedInRules = 'grounded_in_rules';
    case ParticipantDataRequired = 'participant_data_required';
    case MissingRule = 'missing_rule';
    case InsufficientContext = 'insufficient_context';
    case UnsafeRequest = 'unsafe_request';
    case OutOfScope = 'out_of_scope';
}
