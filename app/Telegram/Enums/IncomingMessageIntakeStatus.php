<?php

namespace App\Telegram\Enums;

enum IncomingMessageIntakeStatus: string
{
    case ReadyForClassification = 'ready_for_classification';
    case AddedToOpenTicket = 'added_to_open_ticket';
    case DuplicateUpdate = 'duplicate_update';
}
