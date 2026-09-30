<?php

namespace Tests\Fakes;

use App\Models\Message;
use App\Telegram\Contracts\IncomingMessageIntake;
use App\Telegram\Data\IncomingMessageIntakeResult;
use App\Telegram\Data\IncomingTelegramMessage;
use App\Telegram\Enums\IncomingMessageIntakeStatus;

final class FakeIncomingMessageIntake implements IncomingMessageIntake
{
    /** @var list<IncomingTelegramMessage> */
    public array $received = [];

    /** @var list<int> */
    public array $duplicateUpdateIds = [];

    public function handle(IncomingTelegramMessage $incoming): IncomingMessageIntakeResult
    {
        $this->received[] = $incoming;
        $message = new Message;
        $message->id = $incoming->updateId;

        $status = in_array($incoming->updateId, $this->duplicateUpdateIds, true)
            ? IncomingMessageIntakeStatus::DuplicateUpdate
            : IncomingMessageIntakeStatus::ReadyForClassification;

        return new IncomingMessageIntakeResult($status, $message);
    }
}
