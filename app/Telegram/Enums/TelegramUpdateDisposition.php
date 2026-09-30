<?php

namespace App\Telegram\Enums;

enum TelegramUpdateDisposition: string
{
    case IncomingText = 'incoming_text';
    case UnsupportedPrivateMessage = 'unsupported_private_message';
    case Ignored = 'ignored';
}
