<?php

namespace App\Telegram\Data;

final readonly class TelegramTransportError
{
    public function __construct(
        public string $operation,
        public ?int $httpStatus,
        public ?int $apiErrorCode,
        public string $description,
    ) {}

    public function publicMessage(): string
    {
        $details = [];

        if ($this->httpStatus !== null) {
            $details[] = "HTTP {$this->httpStatus}";
        }

        if ($this->apiErrorCode !== null) {
            $details[] = "Telegram {$this->apiErrorCode}";
        }

        $suffix = $details === [] ? '' : ' ('.implode(', ', $details).')';

        return "Telegram {$this->operation} failed{$suffix}: {$this->description}";
    }
}
