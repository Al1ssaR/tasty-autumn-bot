<?php

namespace App\Operator;

use App\Models\Message;
use App\Models\SupportTicket;
use App\Support\Contracts\Clock;
use App\Telegram\TelegramMessageDelivery;
use Illuminate\Support\Facades\DB;

final class TicketCloseService
{
    private const CLOSE_NOTIFICATION = 'Обращение закрыто. Если у вас появится новый вопрос, просто напишите сюда.';

    public function __construct(
        private readonly Clock $clock,
        private readonly TelegramMessageDelivery $delivery,
    ) {}

    public function close(int $ticketId): SupportTicket
    {
        [$ticket, $message] = DB::transaction(function () use ($ticketId): array {
            $ticket = SupportTicket::query()
                ->lockForUpdate()
                ->findOrFail($ticketId);

            if ($ticket->status !== 'open') {
                return [$ticket->refresh(), null];
            }

            $ticket->update([
                'status' => 'closed',
                'closed_at' => $this->clock->now(),
            ]);

            $message = Message::query()->create([
                'telegram_participant_id' => $ticket->telegram_participant_id,
                'support_ticket_id' => $ticket->id,
                'operator_user_id' => null,
                'bot_decision_id' => null,
                'author_type' => 'system',
                'body' => self::CLOSE_NOTIFICATION,
                'telegram_update_id' => null,
                'telegram_message_id' => null,
                'delivery_status' => 'pending',
                'telegram_sent_at' => null,
                'delivery_error' => null,
                'delivery_attempt_count' => 0,
            ]);

            return [$ticket->refresh(), $message];
        });

        if ($message !== null) {
            $this->delivery->deliver($message);
        }

        return $ticket->refresh();
    }
}
