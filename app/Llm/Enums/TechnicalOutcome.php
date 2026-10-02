<?php

namespace App\Llm\Enums;

enum TechnicalOutcome: string
{
    case Valid = 'valid';
    case MalformedResponse = 'malformed_response';
    case Timeout = 'timeout';
    case ApiFailure = 'api_failure';
    case ApplicationFailure = 'application_failure';
}
