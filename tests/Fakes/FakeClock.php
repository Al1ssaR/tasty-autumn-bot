<?php

namespace Tests\Fakes;

use App\Support\Contracts\Clock;
use DateTimeImmutable;

final readonly class FakeClock implements Clock
{
    public function __construct(private DateTimeImmutable $time) {}

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
