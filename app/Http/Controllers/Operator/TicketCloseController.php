<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Operator\TicketCloseService;
use Illuminate\Http\RedirectResponse;

final class TicketCloseController extends Controller
{
    public function __invoke(
        SupportTicket $ticket,
        TicketCloseService $service,
    ): RedirectResponse {
        $service->close($ticket->id);

        return redirect()
            ->route('tickets.show', $ticket)
            ->with('success', 'Обращение закрыто.');
    }
}
