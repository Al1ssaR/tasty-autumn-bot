<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'telegram_participant_id',
    'trigger_message_id',
    'status',
    'first_operator_response_at',
    'closed_at',
])]
class SupportTicket extends Model
{
    protected function casts(): array
    {
        return [
            'first_operator_response_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TelegramParticipant::class, 'telegram_participant_id');
    }

    public function triggerMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'trigger_message_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
