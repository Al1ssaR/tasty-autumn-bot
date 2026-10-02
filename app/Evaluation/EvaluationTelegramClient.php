<?php

namespace App\Evaluation;

use App\Telegram\Contracts\TelegramClient;
use App\Telegram\Data\TelegramBotProfile;
use App\Telegram\Data\TelegramSendResult;
use LogicException;

final class EvaluationTelegramClient implements TelegramClient
{
    public int $sentMessages = 0;

    public function getUpdates(?int $offset = null): array
    {
        throw new LogicException('Evaluation transport cannot receive Telegram updates.');
    }

    public function sendMessage(int $chatId, string $text): TelegramSendResult
    {
        $this->sentMessages++;

        return TelegramSendResult::success(9_000_001);
    }

    public function getMe(): TelegramBotProfile
    {
        throw new LogicException('Evaluation transport cannot call Telegram getMe.');
    }
}
