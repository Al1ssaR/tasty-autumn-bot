<?php

namespace Tests\Fakes;

use App\Telegram\Contracts\TelegramClient;
use App\Telegram\Data\TelegramBotProfile;
use App\Telegram\Data\TelegramSendResult;
use App\Telegram\Data\TelegramUpdate;
use App\Telegram\Exceptions\TelegramTransportException;

final class FakeTelegramClient implements TelegramClient
{
    /** @var list<list<TelegramUpdate>> */
    public array $updateBatches = [];

    /** @var list<?int> */
    public array $requestedOffsets = [];

    /** @var list<array{chat_id: int, text: string}> */
    public array $sentMessages = [];

    public ?TelegramTransportException $getUpdatesException = null;

    public TelegramSendResult $sendResult;

    public TelegramBotProfile $botProfile;

    public function __construct()
    {
        $this->sendResult = TelegramSendResult::success(1);
        $this->botProfile = new TelegramBotProfile(1, 'Test Bot', 'test_bot');
    }

    public function getUpdates(?int $offset = null): array
    {
        $this->requestedOffsets[] = $offset;

        if ($this->getUpdatesException !== null) {
            throw $this->getUpdatesException;
        }

        return array_shift($this->updateBatches) ?? [];
    }

    public function sendMessage(int $chatId, string $text): TelegramSendResult
    {
        $this->sentMessages[] = ['chat_id' => $chatId, 'text' => $text];

        return $this->sendResult;
    }

    public function getMe(): TelegramBotProfile
    {
        return $this->botProfile;
    }
}
