<?php

namespace App\Support;

use App\Support\Contracts\Clock;
use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(
            (string) config('bot.business_timezone', 'Europe/Moscow'),
        ));
    }
}
