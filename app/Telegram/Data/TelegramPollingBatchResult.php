<?php

namespace App\Telegram\Data;

use App\Telegram\Enums\IncomingMessageIntakeStatus;

final readonly class TelegramPollingBatchResult
{
    /** @param list<IncomingMessageIntakeStatus> $intakeStatuses */
    public function __construct(
        public ?int $nextOffset,
        public int $receivedUpdates,
        public int $ignoredUpdates,
        public int $unsupportedUpdates,
        public array $intakeStatuses,
    ) {}
}
