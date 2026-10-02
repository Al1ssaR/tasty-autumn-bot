<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operator\StoreTicketReplyRequest;
use App\Models\SupportTicket;
use App\Models\User;
use App\Operator\Exceptions\TicketIsClosedException;
use App\Operator\TicketReplyService;
use Illuminate\Http\RedirectResponse;

final class TicketReplyController extends Controller
{
    public function store(
        StoreTicketReplyRequest $request,
        SupportTicket $ticket,
        TicketReplyService $service,
    ): RedirectResponse {
        /** @var User $operator */
        $operator = $request->user();

        try {
            $message = $service->reply(
                $ticket->id,
                $operator,
                $request->validated('body'),
            );
        } catch (TicketIsClosedException $exception) {
            return redirect()
                ->route('tickets.show', $ticket)
                ->with('error', $exception->getMessage());
        }

        $notice = $message->delivery_status === 'sent'
            ? 'Ответ отправлен участнику.'
            : 'Ответ сохранён, но доставить его в Telegram не удалось.';

        return redirect()
            ->route('tickets.show', $ticket)
            ->with($message->delivery_status === 'sent' ? 'success' : 'error', $notice);
    }
}
