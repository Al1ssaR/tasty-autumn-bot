<?php

namespace App\Operator\Exceptions;

use DomainException;

final class TicketIsClosedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Нельзя ответить в закрытое обращение.');
    }
}
