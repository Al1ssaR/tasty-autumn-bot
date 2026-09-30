<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'telegram_participant_id',
    'support_ticket_id',
    'operator_user_id',
    'bot_decision_id',
    'author_type',
    'body',
    'telegram_update_id',
    'telegram_message_id',
    'delivery_status',
    'telegram_sent_at',
    'delivery_error',
    'delivery_attempt_count',
])]
class Message extends Model
{
    protected function casts(): array
    {
        return [
            'telegram_update_id' => 'integer',
            'telegram_message_id' => 'integer',
            'telegram_sent_at' => 'datetime',
            'delivery_attempt_count' => 'integer',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TelegramParticipant::class, 'telegram_participant_id');
    }

    public function supportTicket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_user_id');
    }

    public function decision(): HasOne
    {
        return $this->hasOne(BotDecision::class, 'incoming_message_id');
    }

    public function botDecision(): BelongsTo
    {
        return $this->belongsTo(BotDecision::class);
    }
}
