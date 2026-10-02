<?php

return [
    'provider' => env('LLM_PROVIDER'),
    'model' => env('LLM_MODEL'),
    'api_key' => env('LLM_API_KEY'),
    'base_url' => env('LLM_BASE_URL'),
    'timeout' => (int) env('LLM_TIMEOUT', 30),
    'reasoning_effort' => env('LLM_REASONING_EFFORT', 'medium'),
];
