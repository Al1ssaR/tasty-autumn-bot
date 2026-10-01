<?php

namespace App\Operator;

use App\Models\SupportTicket;
use App\Support\Contracts\Clock;
use Illuminate\Support\Facades\DB;

final class TicketCloseService
{
    public function __construct(private readonly Clock $clock) {}

    public function close(int $ticketId): SupportTicket
    {
        return DB::transaction(function () use ($ticketId): SupportTicket {
            $ticket = SupportTicket::query()
                ->lockForUpdate()
                ->findOrFail($ticketId);

            if ($ticket->status === 'open') {
                $ticket->update([
                    'status' => 'closed',
                    'closed_at' => $this->clock->now(),
                ]);
            }

            return $ticket->refresh();
        });
    }
}
