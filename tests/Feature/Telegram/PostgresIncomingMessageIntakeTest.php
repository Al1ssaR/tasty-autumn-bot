<?php

namespace Tests\Feature\Telegram;

use App\Models\BotDecision;
use App\Models\SupportTicket;
use App\Telegram\Data\IncomingTelegramMessage;
use App\Telegram\Enums\IncomingMessageIntakeStatus;
use App\Telegram\PostgresIncomingMessageIntake;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresIncomingMessageIntakeTest extends TestCase
{
    use DatabaseTransactions;

    private int $identifier = 800000;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Telegram intake invariants require PostgreSQL.');
        }
    }

    public function test_first_text_creates_participant_and_message_ready_for_classification(): void
    {
        $incoming = $this->incoming(text: '  Исходный ТЕКСТ!!!  ');

        $result = $this->intake()->handle($incoming);

        $this->assertSame(
            IncomingMessageIntakeStatus::ReadyForClassification,
            $result->status,
        );
        $this->assertDatabaseHas('telegram_participants', [
            'telegram_chat_id' => $incoming->chatId,
        ]);
        $this->assertDatabaseHas('messages', [
            'id' => $result->message->id,
            'telegram_participant_id' => $result->message->telegram_participant_id,
            'support_ticket_id' => null,
            'author_type' => 'participant',
            'body' => $incoming->text,
            'telegram_update_id' => $incoming->updateId,
            'telegram_message_id' => $incoming->messageId,
            'delivery_status' => 'not_applicable',
        ]);
        $this->assertDatabaseCount('bot_decisions', 0);
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_second_text_reuses_participant(): void
    {
        $chatId = $this->nextIdentifier();
        $first = $this->intake()->handle($this->incoming(chatId: $chatId));
        $second = $this->intake()->handle($this->incoming(chatId: $chatId));

        $this->assertSame(
            $first->message->telegram_participant_id,
            $second->message->telegram_participant_id,
        );
        $this->assertDatabaseCount('telegram_participants', 1);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_duplicate_update_uses_database_unique_violation_and_creates_no_duplicates(): void
    {
        $incoming = $this->incoming();
        $first = $this->intake()->handle($incoming);
        $duplicate = $this->intake()->handle($incoming);

        $this->assertSame(IncomingMessageIntakeStatus::DuplicateUpdate, $duplicate->status);
        $this->assertSame($first->message->id, $duplicate->message->id);
        $this->assertDatabaseCount('telegram_participants', 1);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_message_is_added_to_existing_open_ticket_without_new_decision_or_ticket(): void
    {
        $chatId = $this->nextIdentifier();
        $trigger = $this->intake()->handle($this->incoming(chatId: $chatId));
        $decision = BotDecision::query()->create([
            'incoming_message_id' => $trigger->message->id,
            'action' => 'escalate',
            'business_reason' => 'participant_data_required',
            'technical_outcome' => 'valid',
            'rule_references' => [],
            'provider' => 'test-provider',
            'model' => 'test-model',
            'prompt_version' => 'test-v1',
            'rules_hash' => str_repeat('a', 64),
            'decided_at' => now(),
        ]);
        $ticket = SupportTicket::query()->create([
            'telegram_participant_id' => $trigger->message->telegram_participant_id,
            'trigger_message_id' => $trigger->message->id,
            'status' => 'open',
        ]);

        $result = $this->intake()->handle($this->incoming(
            chatId: $chatId,
            text: 'Следующее сообщение участника',
        ));

        $this->assertSame(IncomingMessageIntakeStatus::AddedToOpenTicket, $result->status);
        $this->assertSame($ticket->id, $result->message->support_ticket_id);
        $this->assertDatabaseCount('telegram_participants', 1);
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseCount('bot_decisions', 1);
        $this->assertTrue($decision->is(BotDecision::query()->firstOrFail()));
    }

    private function intake(): PostgresIncomingMessageIntake
    {
        return $this->app->make(PostgresIncomingMessageIntake::class);
    }

    private function incoming(
        ?int $updateId = null,
        ?int $chatId = null,
        ?int $messageId = null,
        string $text = 'Question',
    ): IncomingTelegramMessage {
        return new IncomingTelegramMessage(
            $updateId ?? $this->nextIdentifier(),
            $chatId ?? $this->nextIdentifier(),
            $messageId ?? $this->nextIdentifier(),
            $text,
        );
    }

    private function nextIdentifier(): int
    {
        return ++$this->identifier;
    }
}
