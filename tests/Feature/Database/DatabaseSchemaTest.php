<?php

namespace Tests\Feature\Database;

use App\Models\BotDecision;
use App\Models\Message;
use App\Models\SupportTicket;
use App\Models\TelegramParticipant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use DatabaseTransactions;

    private int $identifier = 100_000;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Database schema invariants require PostgreSQL.');
        }
    }

    public function test_duplicate_telegram_chat_id_is_rejected(): void
    {
        $this->createParticipant(10);

        $this->expectException(QueryException::class);

        $this->createParticipant(10);
    }

    public function test_duplicate_non_null_telegram_update_id_is_rejected(): void
    {
        $participantId = $this->createParticipant();
        $this->createParticipantMessage($participantId, 777);

        $this->expectException(QueryException::class);

        $this->createParticipantMessage($participantId, 777);
    }

    public function test_multiple_null_telegram_update_ids_are_allowed(): void
    {
        $participantId = $this->createParticipant();

        $this->createOutgoingMessage($participantId);
        $this->createOutgoingMessage($participantId);

        $this->assertDatabaseCount('messages', 2);
    }

    public function test_incoming_message_cannot_have_two_decisions(): void
    {
        $participantId = $this->createParticipant();
        $messageId = $this->createParticipantMessage($participantId);
        $this->createDecision($messageId);

        $this->expectException(QueryException::class);

        $this->createDecision($messageId);
    }

    public function test_decision_cannot_have_two_response_messages(): void
    {
        $participantId = $this->createParticipant();
        $incomingMessageId = $this->createParticipantMessage($participantId);
        $decisionId = $this->createDecision($incomingMessageId, ['action' => 'answer']);
        $this->createOutgoingMessage($participantId, ['bot_decision_id' => $decisionId]);

        $this->expectException(QueryException::class);

        $this->createOutgoingMessage($participantId, ['bot_decision_id' => $decisionId]);
    }

    public function test_trigger_message_cannot_create_two_tickets(): void
    {
        $participantId = $this->createParticipant();
        $triggerMessageId = $this->createParticipantMessage($participantId);
        $this->createDecision($triggerMessageId);
        $this->createTicket($participantId, $triggerMessageId, 'closed');

        $this->expectException(QueryException::class);

        $this->createTicket($participantId, $triggerMessageId, 'closed');
    }

    public function test_participant_can_have_multiple_closed_tickets(): void
    {
        $participantId = $this->createParticipant();

        $this->createEscalatedTicket($participantId, 'closed');
        $this->createEscalatedTicket($participantId, 'closed');

        $this->assertDatabaseCount('support_tickets', 2);
    }

    public function test_participant_cannot_have_two_open_tickets(): void
    {
        $participantId = $this->createParticipant();
        $this->createEscalatedTicket($participantId);

        $this->expectException(QueryException::class);

        $this->createEscalatedTicket($participantId);
    }

    public function test_different_participants_can_have_their_own_open_ticket(): void
    {
        $firstParticipantId = $this->createParticipant();
        $secondParticipantId = $this->createParticipant();

        $this->createEscalatedTicket($firstParticipantId);
        $this->createEscalatedTicket($secondParticipantId);

        $this->assertDatabaseCount('support_tickets', 2);
    }

    public function test_invalid_technical_outcome_cannot_answer(): void
    {
        $messageId = $this->createParticipantMessage($this->createParticipant());

        $this->expectException(QueryException::class);

        $this->createDecision($messageId, [
            'action' => 'answer',
            'business_reason' => null,
            'technical_outcome' => 'timeout',
        ]);
    }

    public function test_invalid_technical_outcome_cannot_have_business_reason(): void
    {
        $messageId = $this->createParticipantMessage($this->createParticipant());

        $this->expectException(QueryException::class);

        $this->createDecision($messageId, [
            'business_reason' => 'missing_rule',
            'technical_outcome' => 'api_failure',
        ]);
    }

    public function test_invalid_technical_outcome_requires_empty_rule_references(): void
    {
        $messageId = $this->createParticipantMessage($this->createParticipant());

        $this->expectException(QueryException::class);

        $this->createDecision($messageId, [
            'business_reason' => null,
            'technical_outcome' => 'malformed_response',
            'rule_references' => json_encode(['rule-1'], JSON_THROW_ON_ERROR),
        ]);
    }

    public function test_valid_technical_outcome_requires_business_reason(): void
    {
        $messageId = $this->createParticipantMessage($this->createParticipant());

        $this->expectException(QueryException::class);

        $this->createDecision($messageId, ['business_reason' => null]);
    }

    public function test_rule_references_must_be_a_json_array(): void
    {
        $messageId = $this->createParticipantMessage($this->createParticipant());

        $this->expectException(QueryException::class);

        $this->createDecision($messageId, [
            'rule_references' => json_encode(['rule' => '1'], JSON_THROW_ON_ERROR),
        ]);
    }

    public function test_closed_ticket_requires_closed_at(): void
    {
        [$participantId, $triggerMessageId] = $this->createEscalationTrigger();

        $this->expectException(QueryException::class);

        DB::table('support_tickets')->insert(array_merge(
            $this->ticketAttributes($participantId, $triggerMessageId, 'closed'),
            ['closed_at' => null],
        ));
    }

    public function test_open_ticket_cannot_have_closed_at(): void
    {
        [$participantId, $triggerMessageId] = $this->createEscalationTrigger();

        $this->expectException(QueryException::class);

        DB::table('support_tickets')->insert($this->ticketAttributes(
            $participantId,
            $triggerMessageId,
            'open',
            now(),
        ));
    }

    public function test_ticket_times_cannot_precede_creation(): void
    {
        [$participantId, $triggerMessageId] = $this->createEscalationTrigger();
        $createdAt = now();

        $this->expectException(QueryException::class);

        DB::table('support_tickets')->insert([
            'telegram_participant_id' => $participantId,
            'trigger_message_id' => $triggerMessageId,
            'status' => 'open',
            'first_operator_response_at' => $createdAt->copy()->subSecond(),
            'closed_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    public function test_closed_at_cannot_precede_ticket_creation(): void
    {
        [$participantId, $triggerMessageId] = $this->createEscalationTrigger();
        $createdAt = now();

        $this->expectException(QueryException::class);

        DB::table('support_tickets')->insert([
            'telegram_participant_id' => $participantId,
            'trigger_message_id' => $triggerMessageId,
            'status' => 'closed',
            'first_operator_response_at' => null,
            'closed_at' => $createdAt->copy()->subSecond(),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    public function test_participant_message_cannot_have_outgoing_delivery_state(): void
    {
        $participantId = $this->createParticipant();

        $this->expectException(QueryException::class);

        DB::table('messages')->insert($this->participantMessageAttributes($participantId, [
            'delivery_status' => 'sent',
            'telegram_sent_at' => now(),
            'delivery_attempt_count' => 1,
        ]));
    }

    public function test_sent_outgoing_message_requires_telegram_message_id(): void
    {
        $participantId = $this->createParticipant();

        $this->expectException(QueryException::class);

        DB::table('messages')->insert($this->outgoingMessageAttributes($participantId, [
            'delivery_status' => 'sent',
            'telegram_sent_at' => now(),
            'delivery_attempt_count' => 1,
        ]));
    }

    public function test_sent_outgoing_message_requires_sent_at(): void
    {
        $participantId = $this->createParticipant();

        $this->expectException(QueryException::class);

        DB::table('messages')->insert($this->outgoingMessageAttributes($participantId, [
            'telegram_message_id' => $this->nextIdentifier(),
            'delivery_status' => 'sent',
            'delivery_attempt_count' => 1,
        ]));
    }

    public function test_failed_outgoing_message_requires_delivery_error(): void
    {
        $participantId = $this->createParticipant();

        $this->expectException(QueryException::class);

        DB::table('messages')->insert($this->outgoingMessageAttributes($participantId, [
            'delivery_status' => 'failed',
            'delivery_attempt_count' => 1,
        ]));
    }

    public function test_operator_message_requires_operator_user(): void
    {
        [$participantId, , $ticketId] = $this->createEscalatedTicket();

        $this->expectException(QueryException::class);

        DB::table('messages')->insert($this->operatorMessageAttributes(
            $participantId,
            $ticketId,
            null,
        ));
    }

    public function test_operator_message_requires_support_ticket(): void
    {
        $participantId = $this->createParticipant();
        $operatorId = $this->createUser();

        $this->expectException(QueryException::class);

        DB::table('messages')->insert($this->operatorMessageAttributes(
            $participantId,
            null,
            $operatorId,
        ));
    }

    public function test_delivery_attempt_count_cannot_be_negative(): void
    {
        $participantId = $this->createParticipant();

        $this->expectException(QueryException::class);

        DB::table('messages')->insert($this->outgoingMessageAttributes($participantId, [
            'delivery_attempt_count' => -1,
        ]));
    }

    public function test_message_body_cannot_be_blank(): void
    {
        $participantId = $this->createParticipant();

        $this->expectException(QueryException::class);

        DB::table('messages')->insert($this->outgoingMessageAttributes($participantId, [
            'body' => '   ',
        ]));
    }

    public function test_main_eloquent_relationships_work(): void
    {
        $participant = TelegramParticipant::query()->create([
            'telegram_chat_id' => $this->nextIdentifier(),
        ]);
        $incoming = Message::query()->create($this->participantMessageAttributes($participant->id));
        $decision = BotDecision::query()->create($this->decisionAttributes($incoming->id, [
            'rule_references' => [],
        ]));
        $ticket = SupportTicket::query()->create([
            'telegram_participant_id' => $participant->id,
            'trigger_message_id' => $incoming->id,
            'status' => 'open',
        ]);
        $response = Message::query()->create($this->outgoingMessageAttributes($participant->id, [
            'support_ticket_id' => $ticket->id,
            'bot_decision_id' => $decision->id,
        ]));
        $operator = User::query()->create([
            'name' => 'Operator',
            'email' => $this->nextIdentifier().'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $operatorMessage = Message::query()->create($this->operatorMessageAttributes(
            $participant->id,
            $ticket->id,
            $operator->id,
        ));

        $this->assertTrue($participant->messages->contains($incoming));
        $this->assertTrue($participant->supportTickets->contains($ticket));
        $this->assertTrue($incoming->decision->is($decision));
        $this->assertTrue($response->botDecision->is($decision));
        $this->assertTrue($decision->incomingMessage->is($incoming));
        $this->assertTrue($decision->responseMessage->is($response));
        $this->assertTrue($ticket->triggerMessage->is($incoming));
        $this->assertTrue($ticket->messages->contains($response));
        $this->assertTrue($operator->operatorMessages->contains($operatorMessage));
        $this->assertSame([], $decision->rule_references);
        $this->assertTrue($operator->is_active);
    }

    public function test_participant_with_history_cannot_be_deleted(): void
    {
        $participantId = $this->createParticipant();
        $this->createParticipantMessage($participantId);

        $this->expectException(QueryException::class);

        DB::table('telegram_participants')->where('id', $participantId)->delete();
    }

    public function test_user_with_operator_messages_cannot_be_deleted(): void
    {
        [$participantId, , $ticketId] = $this->createEscalatedTicket();
        $userId = $this->createUser();
        DB::table('messages')->insert($this->operatorMessageAttributes(
            $participantId,
            $ticketId,
            $userId,
        ));

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $userId)->delete();
    }

    public function test_ticket_with_history_cannot_be_deleted(): void
    {
        [$participantId, , $ticketId] = $this->createEscalatedTicket();
        $this->createOutgoingMessage($participantId, ['support_ticket_id' => $ticketId]);

        $this->expectException(QueryException::class);

        DB::table('support_tickets')->where('id', $ticketId)->delete();
    }

    public function test_decision_with_response_cannot_be_deleted(): void
    {
        $participantId = $this->createParticipant();
        $incomingMessageId = $this->createParticipantMessage($participantId);
        $decisionId = $this->createDecision($incomingMessageId, ['action' => 'answer']);
        $this->createOutgoingMessage($participantId, ['bot_decision_id' => $decisionId]);

        $this->expectException(QueryException::class);

        DB::table('bot_decisions')->where('id', $decisionId)->delete();
    }

    private function createParticipant(?int $telegramChatId = null): int
    {
        return DB::table('telegram_participants')->insertGetId([
            'telegram_chat_id' => $telegramChatId ?? $this->nextIdentifier(),
        ]);
    }

    private function createParticipantMessage(
        int $participantId,
        ?int $telegramUpdateId = null,
    ): int {
        return DB::table('messages')->insertGetId($this->participantMessageAttributes(
            $participantId,
            ['telegram_update_id' => $telegramUpdateId ?? $this->nextIdentifier()],
        ));
    }

    /** @param array<string, mixed> $overrides */
    private function participantMessageAttributes(int $participantId, array $overrides = []): array
    {
        return array_merge([
            'telegram_participant_id' => $participantId,
            'support_ticket_id' => null,
            'operator_user_id' => null,
            'bot_decision_id' => null,
            'author_type' => 'participant',
            'body' => 'Participant question',
            'telegram_update_id' => $this->nextIdentifier(),
            'telegram_message_id' => $this->nextIdentifier(),
            'delivery_status' => 'not_applicable',
            'telegram_sent_at' => null,
            'delivery_error' => null,
            'delivery_attempt_count' => 0,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function createOutgoingMessage(int $participantId, array $overrides = []): int
    {
        return DB::table('messages')->insertGetId($this->outgoingMessageAttributes(
            $participantId,
            $overrides,
        ));
    }

    /** @param array<string, mixed> $overrides */
    private function outgoingMessageAttributes(int $participantId, array $overrides = []): array
    {
        return array_merge([
            'telegram_participant_id' => $participantId,
            'support_ticket_id' => null,
            'operator_user_id' => null,
            'bot_decision_id' => null,
            'author_type' => 'bot',
            'body' => 'Automated response',
            'telegram_update_id' => null,
            'telegram_message_id' => null,
            'delivery_status' => 'pending',
            'telegram_sent_at' => null,
            'delivery_error' => null,
            'delivery_attempt_count' => 0,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function createDecision(int $incomingMessageId, array $overrides = []): int
    {
        return DB::table('bot_decisions')->insertGetId($this->decisionAttributes(
            $incomingMessageId,
            $overrides,
        ));
    }

    /** @param array<string, mixed> $overrides */
    private function decisionAttributes(int $incomingMessageId, array $overrides = []): array
    {
        return array_merge([
            'incoming_message_id' => $incomingMessageId,
            'action' => 'escalate',
            'business_reason' => 'missing_rule',
            'technical_outcome' => 'valid',
            'rule_references' => json_encode([], JSON_THROW_ON_ERROR),
            'provider' => 'test-provider',
            'model' => 'test-model',
            'prompt_version' => 'test-v1',
            'rules_hash' => str_repeat('a', 64),
            'decided_at' => now(),
        ], $overrides);
    }

    /** @return array{int, int, int} */
    private function createEscalatedTicket(?int $participantId = null, string $status = 'open'): array
    {
        [$participantId, $triggerMessageId] = $this->createEscalationTrigger($participantId);
        $ticketId = $this->createTicket($participantId, $triggerMessageId, $status);

        return [$participantId, $triggerMessageId, $ticketId];
    }

    /** @return array{int, int} */
    private function createEscalationTrigger(?int $participantId = null): array
    {
        $participantId ??= $this->createParticipant();
        $triggerMessageId = $this->createParticipantMessage($participantId);
        $this->createDecision($triggerMessageId);

        return [$participantId, $triggerMessageId];
    }

    private function createTicket(int $participantId, int $triggerMessageId, string $status): int
    {
        return DB::table('support_tickets')->insertGetId($this->ticketAttributes(
            $participantId,
            $triggerMessageId,
            $status,
        ));
    }

    /** @return array<string, mixed> */
    private function ticketAttributes(
        int $participantId,
        int $triggerMessageId,
        string $status,
        ?Carbon $closedAt = null,
    ): array {
        $createdAt = now()->subSecond();

        return [
            'telegram_participant_id' => $participantId,
            'trigger_message_id' => $triggerMessageId,
            'status' => $status,
            'first_operator_response_at' => null,
            'closed_at' => $status === 'closed' ? ($closedAt ?? now()) : $closedAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    private function createUser(): int
    {
        return DB::table('users')->insertGetId([
            'name' => 'Operator',
            'email' => $this->nextIdentifier().'@example.test',
            'password' => password_hash('password', PASSWORD_BCRYPT),
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function operatorMessageAttributes(
        int $participantId,
        ?int $ticketId,
        ?int $operatorId,
    ): array {
        return [
            'telegram_participant_id' => $participantId,
            'support_ticket_id' => $ticketId,
            'operator_user_id' => $operatorId,
            'bot_decision_id' => null,
            'author_type' => 'operator',
            'body' => 'Operator response',
            'telegram_update_id' => null,
            'telegram_message_id' => null,
            'delivery_status' => 'pending',
            'telegram_sent_at' => null,
            'delivery_error' => null,
            'delivery_attempt_count' => 0,
        ];
    }

    private function nextIdentifier(): int
    {
        return ++$this->identifier;
    }
}
