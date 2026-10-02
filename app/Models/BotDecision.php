<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'incoming_message_id',
    'action',
    'business_reason',
    'technical_outcome',
    'rule_references',
    'provider',
    'model',
    'prompt_version',
    'rules_hash',
    'decided_at',
])]
class BotDecision extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'rule_references' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function incomingMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'incoming_message_id');
    }

    public function responseMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'bot_decision_id');
    }
}
