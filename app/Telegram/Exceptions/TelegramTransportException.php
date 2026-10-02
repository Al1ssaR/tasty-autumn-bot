<?php

namespace App\Telegram\Exceptions;

use App\Telegram\Data\TelegramTransportError;
use RuntimeException;

final class TelegramTransportException extends RuntimeException
{
    public function __construct(public readonly TelegramTransportError $error)
    {
        parent::__construct($error->publicMessage());
    }
}
