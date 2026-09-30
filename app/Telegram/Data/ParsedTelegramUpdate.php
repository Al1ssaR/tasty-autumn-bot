<?php

namespace App\Telegram\Data;

use App\Telegram\Enums\TelegramUpdateDisposition;

final readonly class ParsedTelegramUpdate
{
    private function __construct(
        public TelegramUpdateDisposition $disposition,
        public ?int $updateId,
        public ?IncomingTelegramMessage $incomingMessage,
        public ?int $unsupportedChatId,
    ) {}

    public static function incoming(IncomingTelegramMessage $message): self
    {
        return new self(
            TelegramUpdateDisposition::IncomingText,
            $message->updateId,
            $message,
            null,
        );
    }

    public static function unsupported(int $updateId, int $chatId): self
    {
        return new self(
            TelegramUpdateDisposition::UnsupportedPrivateMessage,
            $updateId,
            null,
            $chatId,
        );
    }

    public static function ignored(?int $updateId): self
    {
        return new self(TelegramUpdateDisposition::Ignored, $updateId, null, null);
    }
}
