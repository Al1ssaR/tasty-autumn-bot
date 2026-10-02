<?php

namespace App\Telegram\Data;

final readonly class TelegramBotProfile
{
    public function __construct(
        public int $id,
        public string $displayName,
        public ?string $username,
    ) {}
}
