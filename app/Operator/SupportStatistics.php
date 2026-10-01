<?php

namespace App\Operator;

use App\Models\BotDecision;
use App\Models\SupportTicket;

final class SupportStatistics
{
    /** @return array{bot_resolved: int, escalated: int, average_operator_response_seconds: ?float} */
    public function allTime(): array
    {
        $botResolved = BotDecision::query()
            ->join('messages as response', 'response.bot_decision_id', '=', 'bot_decisions.id')
            ->where('bot_decisions.action', 'answer')
            ->where('bot_decisions.technical_outcome', 'valid')
            ->whereIn('response.author_type', ['bot', 'system'])
            ->where('response.delivery_status', 'sent')
            ->count();

        $average = SupportTicket::query()
            ->whereNotNull('first_operator_response_at')
            ->selectRaw(
                'AVG(EXTRACT(EPOCH FROM (first_operator_response_at - created_at))) AS aggregate',
            )
            ->value('aggregate');

        return [
            'bot_resolved' => $botResolved,
            'escalated' => SupportTicket::query()->count(),
            'average_operator_response_seconds' => $average === null ? null : (float) $average,
        ];
    }
}
