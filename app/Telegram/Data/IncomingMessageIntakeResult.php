<?php

namespace App\Telegram\Data;

use App\Models\Message;
use App\Telegram\Enums\IncomingMessageIntakeStatus;

final readonly class IncomingMessageIntakeResult
{
    public function __construct(
        public IncomingMessageIntakeStatus $status,
        public Message $message,
    ) {}
}
