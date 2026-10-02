<?php

namespace Tests\Fakes;

use App\Llm\Contracts\IncomingMessageClassifier;
use App\Llm\Data\ClassificationResult;
use App\Models\BotDecision;
use App\Models\Message;

final class FakeIncomingMessageClassifier implements IncomingMessageClassifier
{
    /** @var list<Message> */
    public array $received = [];

    public function classify(Message $incomingMessage): ClassificationResult
    {
        $this->received[] = $incomingMessage;

        return new ClassificationResult(new BotDecision, new Message, null, true);
    }
}
