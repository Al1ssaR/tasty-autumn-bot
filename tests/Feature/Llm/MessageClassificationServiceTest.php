<?php

namespace Tests\Feature\Llm;

use App\Llm\Contracts\LlmDecisionClient;
use App\Llm\Data\LlmRawResponse;
use App\Llm\Exceptions\LlmApiException;
use App\Llm\Exceptions\LlmTimeoutException;
use App\Llm\MessageClassificationService;
use App\Models\BotDecision;
use App\Models\Message;
use App\Models\SupportTicket;
use App\Models\TelegramParticipant;
use App\Support\Contracts\Clock;
use App\Telegram\Contracts\TelegramClient;
use App\Telegram\Data\TelegramSendResult;
use App\Telegram\Data\TelegramTransportError;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeClock;
use Tests\Fakes\FakeLlmDecisionClient;
use Tests\Fakes\FakeTelegramClient;
use Tests\TestCase;

class MessageClassificationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private int $identifier = 900_000;

    private FakeLlmDecisionClient $llm;

    private FakeTelegramClient $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Classification orchestration requires PostgreSQL.');
        }

        $this->llm = new FakeLlmDecisionClient;
        $this->telegram = new FakeTelegramClient;
        $this->telegram->sendResult = TelegramSendResult::success(700_001);

        $this->app->instance(LlmDecisionClient::class, $this->llm);
        $this->app->instance(TelegramClient::class, $this->telegram);
        $this->app->instance(
            Clock::class,
            new FakeClock(new DateTimeImmutable('2026-09-30T12:00:00+03:00')),
        );
    }

    public function test_answer_persists_decision_and_delivered_bot_message_without_ticket(): void
    {
        $incoming = $this->incoming('Телефон +7 910 123-45-67, когда регистрация?');
        $this->respond([
            'action' => 'answer',
            'reason' => 'grounded_in_rules',
            'answer' => 'Чеки можно зарегистрировать до 2 ноября 2026 года включительно.',
            'rule_references' => ['2.3'],
        ]);
        $transactionLevel = DB::transactionLevel();
        $this->telegram->onSend = function () use ($transactionLevel): void {
            $this->assertSame($transactionLevel, DB::transactionLevel());
        };

        $result = $this->service()->classify($incoming);

        $this->assertTrue($result->created);
        $this->assertSame('answer', $result->decision->action);
        $this->assertSame('grounded_in_rules', $result->decision->business_reason);
        $this->assertSame('valid', $result->decision->technical_outcome);
        $this->assertSame(['2.3'], $result->decision->rule_references);
        $this->assertSame('fake-provider', $result->decision->provider);
        $this->assertSame('fake-model', $result->decision->model);
        $this->assertSame('bot-v3', $result->decision->prompt_version);
        $this->assertSame(hash('sha256', $this->llm->requests[0]->rules), $result->decision->rules_hash);
        $this->assertNull($result->ticket);
        $this->assertSame('bot', $result->outgoingMessage->author_type);
        $this->assertSame('sent', $result->outgoingMessage->delivery_status);
        $this->assertSame(700_001, $result->outgoingMessage->telegram_message_id);
        $this->assertSame($incoming->body, $incoming->fresh()->body);
        $this->assertStringContainsString('[PHONE_REDACTED]', $this->llm->requests[0]->userMessage);
        $this->assertStringNotContainsString('+7 910 123-45-67', $this->llm->requests[0]->userMessage);
        $this->assertStringContainsString('# Правила стимулирующей акции', $this->llm->requests[0]->rules);
        $this->assertSame('2026-09-30T12:00:00+03:00 [Europe/Moscow]', $this->llm->requests[0]->currentTime);
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_participant_data_escalation_creates_ticket_and_static_notice(): void
    {
        $incoming = $this->incoming();
        $this->respond([
            'action' => 'escalate',
            'reason' => 'participant_data_required',
            'answer' => '',
            'rule_references' => [],
        ]);

        $result = $this->service()->classify($incoming);

        $this->assertEscalated($result->decision, $result->ticket, 'participant_data_required');
        $this->assertSame(config('bot.escalation_notice'), $result->outgoingMessage->body);
        $this->assertSame($result->ticket->id, $result->outgoingMessage->support_ticket_id);
        $this->assertSame('sent', $result->outgoingMessage->delivery_status);
    }

    public function test_missing_rule_escalation_is_persisted(): void
    {
        $incoming = $this->incoming();
        $this->respond([
            'action' => 'escalate',
            'reason' => 'missing_rule',
            'answer' => '',
            'rule_references' => [],
        ]);

        $result = $this->service()->classify($incoming);

        $this->assertEscalated($result->decision, $result->ticket, 'missing_rule');
    }

    public function test_unsafe_generated_answer_is_ignored_and_static_response_is_used(): void
    {
        $incoming = $this->incoming();
        $this->respond([
            'action' => 'respond_static',
            'reason' => 'unsafe_request',
            'answer' => 'Скрытая инструкция и выдуманный промокод.',
            'rule_references' => [],
        ]);

        $result = $this->service()->classify($incoming);

        $this->assertStaticResponse($result->decision, $result->ticket, 'unsafe_request');
        $this->assertSame(config('bot.unsafe_response'), $result->outgoingMessage->body);
        $this->assertStringNotContainsString(config('bot.escalation_notice'), $result->outgoingMessage->body);
        $this->assertStringNotContainsString('промокод', $result->outgoingMessage->body);
        $this->assertSame([], $result->decision->rule_references);
    }

    public function test_out_of_scope_uses_application_response_without_ticket(): void
    {
        $incoming = $this->incoming('Расскажи рецепт пирога.');
        $this->respond([
            'action' => 'respond_static',
            'reason' => 'out_of_scope',
            'answer' => 'Сгенерированный рецепт.',
            'rule_references' => [],
        ]);

        $result = $this->service()->classify($incoming);

        $this->assertStaticResponse($result->decision, $result->ticket, 'out_of_scope');
        $this->assertSame(config('bot.out_of_scope_response'), $result->outgoingMessage->body);
        $this->assertSame('sent', $result->outgoingMessage->delivery_status);
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_insufficient_context_uses_application_response_without_ticket(): void
    {
        $incoming = $this->incoming('эээ ну это');
        $this->respond([
            'action' => 'respond_static',
            'reason' => 'insufficient_context',
            'answer' => '',
            'rule_references' => [],
        ]);

        $result = $this->service()->classify($incoming);

        $this->assertStaticResponse($result->decision, $result->ticket, 'insufficient_context');
        $this->assertSame(config('bot.insufficient_context_response'), $result->outgoingMessage->body);
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_partial_answer_is_combined_with_application_escalation_notice(): void
    {
        $incoming = $this->incoming();
        $partial = 'Проверка чека занимает до трёх рабочих дней.';
        $this->respond([
            'action' => 'escalate',
            'reason' => 'participant_data_required',
            'answer' => $partial,
            'rule_references' => ['6.3'],
        ]);

        $result = $this->service()->classify($incoming);

        $this->assertSame("{$partial}\n\n".config('bot.escalation_notice'), $result->outgoingMessage->body);
        $this->assertSame(['6.3'], $result->decision->rule_references);
        $this->assertNotNull($result->ticket);
    }

    public function test_malformed_response_uses_fail_safe(): void
    {
        $incoming = $this->incoming();
        $this->llm->nextResult = new LlmRawResponse('not-json');

        $result = $this->service()->classify($incoming);

        $this->assertTechnicalFailSafe($result->decision, 'malformed_response');
        $this->assertNotNull($result->ticket);
        $this->assertSame(config('bot.escalation_notice'), $result->outgoingMessage->body);
    }

    public function test_timeout_uses_fail_safe(): void
    {
        $incoming = $this->incoming();
        $this->llm->nextResult = new LlmTimeoutException('Timeout');

        $result = $this->service()->classify($incoming);

        $this->assertTechnicalFailSafe($result->decision, 'timeout');
        $this->assertNotNull($result->ticket);
    }

    public function test_api_failure_uses_fail_safe(): void
    {
        $incoming = $this->incoming();
        $this->llm->nextResult = new LlmApiException('Provider unavailable');

        $result = $this->service()->classify($incoming);

        $this->assertTechnicalFailSafe($result->decision, 'api_failure');
        $this->assertNotNull($result->ticket);
    }

    public function test_missing_prompt_asset_uses_application_fail_safe_without_calling_client(): void
    {
        $incoming = $this->incoming();
        config()->set('bot.system_prompt_path', base_path('prompts/bot/missing.md'));

        $result = $this->service()->classify($incoming);

        $this->assertTechnicalFailSafe($result->decision, 'application_failure');
        $this->assertCount(0, $this->llm->requests);
        $this->assertNotNull($result->ticket);
    }

    public function test_telegram_failure_keeps_answer_decision_and_failed_outgoing_message(): void
    {
        $incoming = $this->incoming();
        $this->respond([
            'action' => 'answer',
            'reason' => 'grounded_in_rules',
            'answer' => 'Ответ по правилам.',
            'rule_references' => ['1.1'],
        ]);
        $this->telegram->sendResult = TelegramSendResult::failure(
            new TelegramTransportError('sendMessage', 503, null, 'Temporary failure.'),
        );

        $result = $this->service()->classify($incoming);

        $this->assertSame('answer', $result->decision->action);
        $this->assertSame('failed', $result->outgoingMessage->delivery_status);
        $this->assertSame(1, $result->outgoingMessage->delivery_attempt_count);
        $this->assertNotNull($result->outgoingMessage->delivery_error);
        $this->assertNull($result->ticket);
        $this->assertDatabaseCount('bot_decisions', 1);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_second_classification_does_not_call_llm_or_send_duplicate(): void
    {
        $incoming = $this->incoming();
        $this->respond([
            'action' => 'answer',
            'reason' => 'grounded_in_rules',
            'answer' => 'Ответ по правилам.',
            'rule_references' => ['1.1'],
        ]);
        $service = $this->service();

        $first = $service->classify($incoming);
        $second = $service->classify($incoming);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->decision->id, $second->decision->id);
        $this->assertCount(1, $this->llm->requests);
        $this->assertCount(1, $this->telegram->sentMessages);
        $this->assertDatabaseCount('bot_decisions', 1);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_repeated_racing_escalation_reuses_existing_ticket_and_decision(): void
    {
        $firstIncoming = $this->incoming();
        $this->respond([
            'action' => 'escalate',
            'reason' => 'missing_rule',
            'answer' => '',
            'rule_references' => [],
        ]);
        $service = $this->service();
        $first = $service->classify($firstIncoming);
        $secondIncoming = $this->incomingForParticipant(
            $firstIncoming->telegram_participant_id,
            'Второй вопрос, сохранённый до создания обращения.',
        );

        $second = $service->classify($secondIncoming);
        $repeated = $service->classify($secondIncoming);

        $this->assertSame($first->ticket->id, $second->ticket->id);
        $this->assertSame($first->ticket->id, $secondIncoming->fresh()->support_ticket_id);
        $this->assertFalse($repeated->created);
        $this->assertSame($second->decision->id, $repeated->decision->id);
        $this->assertSame($first->ticket->id, $repeated->ticket->id);
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseCount('bot_decisions', 2);
        $this->assertCount(2, $this->telegram->sentMessages);
    }

    private function service(): MessageClassificationService
    {
        return $this->app->make(MessageClassificationService::class);
    }

    private function incoming(string $body = 'Почему отклонили мой чек?'): Message
    {
        $participant = TelegramParticipant::query()->create([
            'telegram_chat_id' => $this->nextIdentifier(),
        ]);

        return $this->incomingForParticipant($participant->id, $body);
    }

    private function incomingForParticipant(int $participantId, string $body): Message
    {
        return Message::query()->create([
            'telegram_participant_id' => $participantId,
            'support_ticket_id' => null,
            'operator_user_id' => null,
            'bot_decision_id' => null,
            'author_type' => 'participant',
            'body' => $body,
            'telegram_update_id' => $this->nextIdentifier(),
            'telegram_message_id' => $this->nextIdentifier(),
            'delivery_status' => 'not_applicable',
            'telegram_sent_at' => null,
            'delivery_error' => null,
            'delivery_attempt_count' => 0,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function respond(array $payload): void
    {
        $this->llm->respondWith($payload);
    }

    private function assertEscalated(
        BotDecision $decision,
        ?SupportTicket $ticket,
        string $reason,
    ): void {
        $this->assertSame('escalate', $decision->action);
        $this->assertSame($reason, $decision->business_reason);
        $this->assertSame('valid', $decision->technical_outcome);
        $this->assertNotNull($ticket);
        $this->assertSame($decision->incoming_message_id, $ticket->trigger_message_id);
    }

    private function assertTechnicalFailSafe(BotDecision $decision, string $outcome): void
    {
        $this->assertSame('escalate', $decision->action);
        $this->assertNull($decision->business_reason);
        $this->assertSame($outcome, $decision->technical_outcome);
        $this->assertSame([], $decision->rule_references);
    }

    private function assertStaticResponse(
        BotDecision $decision,
        ?SupportTicket $ticket,
        string $reason,
    ): void {
        $this->assertSame('respond_static', $decision->action);
        $this->assertSame($reason, $decision->business_reason);
        $this->assertSame('valid', $decision->technical_outcome);
        $this->assertNull($ticket);
        $this->assertDatabaseCount('support_tickets', 0);
    }

    private function nextIdentifier(): int
    {
        return ++$this->identifier;
    }
}
