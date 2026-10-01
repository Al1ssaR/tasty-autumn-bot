<?php

namespace App\Operator;

use App\Models\Message;
use App\Models\SupportTicket;
use App\Models\User;
use App\Operator\Exceptions\TicketIsClosedException;
use App\Support\Contracts\Clock;
use App\Telegram\TelegramMessageDelivery;
use Illuminate\Support\Facades\DB;

final class TicketReplyService
{
    public function __construct(
        private readonly TelegramMessageDelivery $delivery,
        private readonly Clock $clock,
    ) {}

    public function reply(int $ticketId, User $operator, string $body): Message
    {
        $message = DB::transaction(function () use ($ticketId, $operator, $body): Message {
            $ticket = SupportTicket::query()
                ->lockForUpdate()
                ->findOrFail($ticketId);

            if ($ticket->status !== 'open') {
                throw new TicketIsClosedException;
            }

            $message = Message::query()->create([
                'telegram_participant_id' => $ticket->telegram_participant_id,
                'support_ticket_id' => $ticket->id,
                'operator_user_id' => $operator->id,
                'bot_decision_id' => null,
                'author_type' => 'operator',
                'body' => trim($body),
                'telegram_update_id' => null,
                'telegram_message_id' => null,
                'delivery_status' => 'pending',
                'telegram_sent_at' => null,
                'delivery_error' => null,
                'delivery_attempt_count' => 0,
            ]);

            if ($ticket->first_operator_response_at === null) {
                $ticket->update([
                    'first_operator_response_at' => $this->clock->now(),
                ]);
            }

            return $message;
        });

        return $this->delivery->deliver($message);
    }
}
