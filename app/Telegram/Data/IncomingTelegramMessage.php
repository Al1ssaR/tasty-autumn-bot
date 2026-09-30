<?php

namespace App\Telegram\Data;

final readonly class IncomingTelegramMessage
{
    public function __construct(
        public int $updateId,
        public int $chatId,
        public int $messageId,
        public string $text,
    ) {}
}
