<?php

namespace App\Llm\Data;

use App\Models\BotDecision;
use App\Models\Message;
use App\Models\SupportTicket;

final readonly class ClassificationResult
{
    public function __construct(
        public BotDecision $decision,
        public Message $outgoingMessage,
        public ?SupportTicket $ticket,
        public bool $created,
    ) {}
}
