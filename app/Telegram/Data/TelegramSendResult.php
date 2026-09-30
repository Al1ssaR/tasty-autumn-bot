<?php

namespace App\Telegram\Data;

final readonly class TelegramSendResult
{
    private function __construct(
        public bool $successful,
        public ?int $messageId,
        public ?TelegramTransportError $error,
    ) {}

    public static function success(int $messageId): self
    {
        return new self(true, $messageId, null);
    }

    public static function failure(TelegramTransportError $error): self
    {
        return new self(false, null, $error);
    }
}
