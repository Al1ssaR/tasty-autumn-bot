<?php

namespace App\Llm\Enums;

enum DecisionAction: string
{
    case Answer = 'answer';
    case Escalate = 'escalate';
    case RespondStatic = 'respond_static';
}
