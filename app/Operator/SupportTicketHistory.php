<?php

namespace App\Operator;

use App\Models\Message;
use App\Models\SupportTicket;
use Illuminate\Database\Eloquent\Collection;

final class SupportTicketHistory
{
    /** @return Collection<int, Message> */
    public function forTicket(SupportTicket $ticket): Collection
    {
        return Message::query()
            ->with('operator:id,name')
            ->where(function ($query) use ($ticket): void {
                $query
                    ->where('id', $ticket->trigger_message_id)
                    ->orWhere('support_ticket_id', $ticket->id);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
