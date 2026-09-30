<?php

namespace App\Telegram;

use App\Telegram\Exceptions\TelegramConfigurationException;

final readonly class TelegramSettings
{
    public function __construct(
        public string $botToken,
        public string $apiBaseUrl,
        public int $longPollTimeout,
        public int $httpTimeout,
        public int $pollBackoffSeconds,
    ) {}

    public static function fromConfig(): self
    {
        $token = config('telegram.bot_token');
        $baseUrl = config('telegram.api_base_url');
        $longPollTimeout = config('telegram.long_poll_timeout');
        $httpTimeout = config('telegram.http_timeout');
        $backoff = config('telegram.poll_backoff_seconds');

        if (! is_string($token) || trim($token) === '') {
            throw new TelegramConfigurationException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        if (! is_string($baseUrl) || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new TelegramConfigurationException('TELEGRAM_API_BASE_URL must be a valid URL.');
        }

        if (! is_int($longPollTimeout) || $longPollTimeout < 0) {
            throw new TelegramConfigurationException('TELEGRAM_LONG_POLL_TIMEOUT must be zero or greater.');
        }

        if (! is_int($httpTimeout) || $httpTimeout <= $longPollTimeout) {
            throw new TelegramConfigurationException(
                'TELEGRAM_HTTP_TIMEOUT must be greater than TELEGRAM_LONG_POLL_TIMEOUT.',
            );
        }

        if (! is_int($backoff) || $backoff < 1 || $backoff > 30) {
            throw new TelegramConfigurationException(
                'TELEGRAM_POLL_BACKOFF_SECONDS must be between 1 and 30.',
            );
        }

        return new self(
            trim($token),
            rtrim($baseUrl, '/'),
            $longPollTimeout,
            $httpTimeout,
            $backoff,
        );
    }
}
