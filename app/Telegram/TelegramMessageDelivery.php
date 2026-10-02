<?php

namespace App\Telegram;

use App\Models\Message;
use App\Support\Contracts\Clock;
use App\Telegram\Contracts\TelegramClient;
use Throwable;

final class TelegramMessageDelivery
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly Clock $clock,
    ) {}

    public function deliver(Message $message): Message
    {
        if ($message->delivery_status !== 'pending' || $message->author_type === 'participant') {
            throw new \InvalidArgumentException('Only pending outgoing messages can be delivered.');
        }

        $attempts = $message->delivery_attempt_count + 1;

        try {
            $result = $this->client->sendMessage(
                $message->participant()->firstOrFail()->telegram_chat_id,
                $this->deliveryBody($message),
            );
        } catch (Throwable) {
            $message->update([
                'delivery_status' => 'failed',
                'telegram_message_id' => null,
                'telegram_sent_at' => null,
                'delivery_error' => 'Telegram sendMessage failed: transport exception.',
                'delivery_attempt_count' => $attempts,
            ]);

            return $message->refresh();
        }

        if ($result->successful && $result->messageId !== null) {
            $message->update([
                'delivery_status' => 'sent',
                'telegram_message_id' => $result->messageId,
                'telegram_sent_at' => $this->clock->now(),
                'delivery_error' => null,
                'delivery_attempt_count' => $attempts,
            ]);
        } else {
            $error = $result->error?->publicMessage()
                ?? 'Telegram sendMessage failed without a safe error result.';

            $message->update([
                'delivery_status' => 'failed',
                'telegram_message_id' => null,
                'telegram_sent_at' => null,
                'delivery_error' => mb_substr($error, 0, 500),
                'delivery_attempt_count' => $attempts,
            ]);
        }

        return $message->refresh();
    }

    private function deliveryBody(Message $message): string
    {
        return $message->author_type === 'operator'
            ? "Оператор: {$message->body}"
            : $message->body;
    }
}
