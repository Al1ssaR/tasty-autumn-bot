<?php

namespace App\Telegram\Contracts;

use App\Telegram\Data\TelegramBotProfile;
use App\Telegram\Data\TelegramSendResult;
use App\Telegram\Data\TelegramUpdate;

interface TelegramClient
{
    /** @return list<TelegramUpdate> */
    public function getUpdates(?int $offset = null): array;

    public function sendMessage(int $chatId, string $text): TelegramSendResult;

    public function getMe(): TelegramBotProfile;
}
