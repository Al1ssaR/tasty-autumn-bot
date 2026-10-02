<?php

namespace App\Telegram;

use App\Models\Message;
use App\Models\SupportTicket;
use App\Models\TelegramParticipant;
use App\Telegram\Contracts\IncomingMessageIntake;
use App\Telegram\Data\IncomingMessageIntakeResult;
use App\Telegram\Data\IncomingTelegramMessage;
use App\Telegram\Enums\IncomingMessageIntakeStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class PostgresIncomingMessageIntake implements IncomingMessageIntake
{
    private const UPDATE_ID_UNIQUE_CONSTRAINT = 'messages_telegram_update_id_unique';

    public function handle(IncomingTelegramMessage $incoming): IncomingMessageIntakeResult
    {
        $participant = $this->ensureParticipant($incoming->chatId);

        try {
            return DB::transaction(function () use ($incoming, $participant): IncomingMessageIntakeResult {
                $lockedParticipant = TelegramParticipant::query()
                    ->lockForUpdate()
                    ->findOrFail($participant->id);
                $openTicket = SupportTicket::query()
                    ->where('telegram_participant_id', $lockedParticipant->id)
                    ->where('status', 'open')
                    ->first();

                $message = Message::query()->create([
                    'telegram_participant_id' => $lockedParticipant->id,
                    'support_ticket_id' => $openTicket?->id,
                    'operator_user_id' => null,
                    'bot_decision_id' => null,
                    'author_type' => 'participant',
                    'body' => $incoming->text,
                    'telegram_update_id' => $incoming->updateId,
                    'telegram_message_id' => $incoming->messageId,
                    'delivery_status' => 'not_applicable',
                    'telegram_sent_at' => null,
                    'delivery_error' => null,
                    'delivery_attempt_count' => 0,
                ]);

                $status = $openTicket === null
                    ? IncomingMessageIntakeStatus::ReadyForClassification
                    : IncomingMessageIntakeStatus::AddedToOpenTicket;

                return new IncomingMessageIntakeResult($status, $message);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isDuplicateUpdateViolation($exception)) {
                throw $exception;
            }

            $message = Message::query()
                ->where('telegram_update_id', $incoming->updateId)
                ->firstOrFail();

            return new IncomingMessageIntakeResult(
                IncomingMessageIntakeStatus::DuplicateUpdate,
                $message,
            );
        }
    }

    private function ensureParticipant(int $chatId): TelegramParticipant
    {
        $now = now();

        TelegramParticipant::query()->insertOrIgnore([
            'telegram_chat_id' => $chatId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return TelegramParticipant::query()
            ->where('telegram_chat_id', $chatId)
            ->firstOrFail();
    }

    private function isDuplicateUpdateViolation(
        UniqueConstraintViolationException $exception,
    ): bool {
        return (string) $exception->getCode() === '23505'
            && $exception->index === self::UPDATE_ID_UNIQUE_CONSTRAINT;
    }
}
