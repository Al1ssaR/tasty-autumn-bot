<?php

namespace App\Llm\Contracts;

use App\Llm\Data\ClassificationResult;
use App\Models\Message;

interface IncomingMessageClassifier
{
    public function classify(Message $incomingMessage): ClassificationResult;
}
