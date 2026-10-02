<?php

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
    'long_poll_timeout' => (int) env('TELEGRAM_LONG_POLL_TIMEOUT', 20),
    'http_timeout' => (int) env('TELEGRAM_HTTP_TIMEOUT', 30),
    'poll_backoff_seconds' => (int) env('TELEGRAM_POLL_BACKOFF_SECONDS', 3),
];
