<?php

namespace App\Telegram\Contracts;

use App\Telegram\Data\IncomingMessageIntakeResult;
use App\Telegram\Data\IncomingTelegramMessage;

interface IncomingMessageIntake
{
    public function handle(IncomingTelegramMessage $incoming): IncomingMessageIntakeResult;
}
