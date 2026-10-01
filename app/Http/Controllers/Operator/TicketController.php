<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Operator\SupportTicketHistory;
use Illuminate\View\View;

final class TicketController extends Controller
{
    public function index(): View
    {
        $tickets = SupportTicket::query()
            ->where('status', 'open')
            ->with('triggerMessage:id,body')
            ->withCount([
                'messages as operator_messages_count' => fn ($query) => $query
                    ->where('author_type', 'operator'),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(25);

        return view('operator.tickets.index', compact('tickets'));
    }

    public function show(
        SupportTicket $ticket,
        SupportTicketHistory $history,
    ): View {
        $ticket->load('participant:id');

        return view('operator.tickets.show', [
            'ticket' => $ticket,
            'messages' => $history->forTicket($ticket),
        ]);
    }
}
