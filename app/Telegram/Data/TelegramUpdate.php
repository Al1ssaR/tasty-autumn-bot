<?php

namespace App\Telegram\Data;

final readonly class TelegramUpdate
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public ?int $updateId,
        public array $payload,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $updateId = $payload['update_id'] ?? null;

        return new self(is_int($updateId) ? $updateId : null, $payload);
    }
}
