<?php

namespace Tests\Feature\Operator;

use App\Models\BotDecision;
use App\Models\Message;
use App\Models\SupportTicket;
use App\Models\TelegramParticipant;
use App\Models\User;
use App\Operator\SupportStatistics;
use App\Support\Contracts\Clock;
use App\Telegram\Contracts\TelegramClient;
use App\Telegram\Data\TelegramSendResult;
use App\Telegram\Data\TelegramTransportError;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Fakes\FakeClock;
use Tests\Fakes\FakeTelegramClient;
use Tests\TestCase;

class OperatorPanelTest extends TestCase
{
    use DatabaseTransactions;

    private int $identifier = 1_200_000;

    private FakeTelegramClient $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Operator panel integration tests require PostgreSQL.');
        }

        $this->telegram = new FakeTelegramClient;
        $this->telegram->sendResult = TelegramSendResult::success(4_200_001);
        $this->app->instance(TelegramClient::class, $this->telegram);
        $this->app->instance(
            Clock::class,
            new FakeClock(new DateTimeImmutable('2026-10-01T10:30:00+03:00')),
        );
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/tickets')->assertRedirect('/login');
    }

    public function test_active_operator_can_login_and_logout(): void
    {
        $operator = $this->operator(password: 'CorrectPassword123!');

        $this->post('/login', [
            'email' => $operator->email,
            'password' => 'CorrectPassword123!',
        ])->assertRedirect('/tickets');

        $this->assertAuthenticatedAs($operator);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_inactive_operator_cannot_login(): void
    {
        $operator = $this->operator(active: false, password: 'CorrectPassword123!');

        $this->post('/login', [
            'email' => $operator->email,
            'password' => 'CorrectPassword123!',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deactivated_operator_session_is_rejected_on_next_request(): void
    {
        $operator = $this->operator();
        $operator->update(['is_active' => false]);

        $this->actingAs($operator)
            ->get('/tickets')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_queue_shows_only_open_tickets_oldest_first_without_n_plus_one(): void
    {
        $operator = $this->operator();
        $older = $this->ticket('Старое открытое обращение', Carbon::parse('2026-10-01 06:00:00 UTC'));
        $newer = $this->ticket('Новое открытое обращение', Carbon::parse('2026-10-01 07:00:00 UTC'));
        $closed = $this->ticket('Закрытое обращение', Carbon::parse('2026-10-01 05:00:00 UTC'));
        $closed->update(['status' => 'closed', 'closed_at' => Carbon::parse('2026-10-01 08:00:00 UTC')]);

        $response = $this->actingAs($operator)->get('/tickets');

        $response
            ->assertOk()
            ->assertSeeInOrder(['Старое открытое обращение', 'Новое открытое обращение'])
            ->assertDontSee('Закрытое обращение');
        $this->assertSame('open', $older->status);
        $this->assertSame('open', $newer->status);
    }

    public function test_ticket_history_includes_unlinked_trigger_and_escapes_participant_html(): void
    {
        $operator = $this->operator();
        $payload = '<script>alert("xss")</script><b>важно</b>';
        $ticket = $this->ticket($payload);
        $this->participantMessage($ticket->participant, 'Уточнение', $ticket);

        $response = $this->actingAs($operator)->get("/tickets/{$ticket->id}");

        $response
            ->assertOk()
            ->assertSee(e($payload), false)
            ->assertDontSee($payload, false)
            ->assertSeeInOrder([e($payload), 'Уточнение'], false);
    }

    public function test_operator_reply_is_saved_before_telegram_delivery_and_sets_first_response_once(): void
    {
        $operator = $this->operator();
        $ticket = $this->ticket('Где мой приз?', Carbon::parse('2026-10-01 06:00:00 UTC'));
        $transactionLevel = DB::transactionLevel();
        $this->telegram->onSend = function () use ($transactionLevel): void {
            $this->assertSame($transactionLevel, DB::transactionLevel());
            $this->assertDatabaseHas('messages', [
                'author_type' => 'operator',
                'delivery_status' => 'pending',
            ]);
        };

        $this->actingAs($operator)
            ->post("/tickets/{$ticket->id}/replies", ['body' => 'Проверили ваш вопрос.'])
            ->assertRedirect("/tickets/{$ticket->id}")
            ->assertSessionHas('success');

        $message = Message::query()->where('author_type', 'operator')->firstOrFail();
        $firstResponse = $ticket->fresh()->first_operator_response_at;
        $this->assertSame('sent', $message->delivery_status);
        $this->assertSame(4_200_001, $message->telegram_message_id);
        $this->assertSame($operator->id, $message->operator_user_id);
        $this->assertSame($ticket->id, $message->support_ticket_id);
        $this->assertNotNull($firstResponse);
        $this->assertCount(1, $this->telegram->sentMessages);

        $this->actingAs($operator)
            ->post("/tickets/{$ticket->id}/replies", ['body' => 'Дополнительный ответ.'])
            ->assertSessionHas('success');

        $this->assertTrue($ticket->fresh()->first_operator_response_at->equalTo($firstResponse));
    }

    public function test_delivery_failure_keeps_operator_message_and_ticket_open(): void
    {
        $operator = $this->operator();
        $ticket = $this->ticket();
        $this->telegram->sendResult = TelegramSendResult::failure(
            new TelegramTransportError('sendMessage', 503, null, 'Temporary failure.'),
        );

        $this->actingAs($operator)
            ->post("/tickets/{$ticket->id}/replies", ['body' => 'Ответ сохранится.'])
            ->assertSessionHas('error');

        $message = Message::query()->where('author_type', 'operator')->firstOrFail();
        $this->assertSame('failed', $message->delivery_status);
        $this->assertNotNull($message->delivery_error);
        $this->assertNotNull($ticket->fresh()->first_operator_response_at);
        $this->assertSame('open', $ticket->fresh()->status);

        $this->actingAs($operator)
            ->get("/tickets/{$ticket->id}")
            ->assertSee('Не доставлено в Telegram')
            ->assertSee('Ответ сохранится.');
    }

    public function test_closed_ticket_hides_form_and_backend_rejects_stale_reply(): void
    {
        $operator = $this->operator();
        $ticket = $this->ticket();
        $ticket->update([
            'status' => 'closed',
            'closed_at' => Carbon::parse('2026-10-01 07:30:00 UTC'),
        ]);

        $this->actingAs($operator)
            ->get("/tickets/{$ticket->id}")
            ->assertDontSee('Отправить в Telegram')
            ->assertSee('Отправка новых ответов недоступна');

        $this->actingAs($operator)
            ->post("/tickets/{$ticket->id}/replies", ['body' => 'Устаревшая форма.'])
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('messages', [
            'author_type' => 'operator',
            'body' => 'Устаревшая форма.',
        ]);
        $this->assertSame([], $this->telegram->sentMessages);
    }

    public function test_closing_ticket_is_idempotent_and_sends_no_telegram_message(): void
    {
        $operator = $this->operator();
        $ticket = $this->ticket();

        $this->actingAs($operator)->post("/tickets/{$ticket->id}/close")->assertSessionHas('success');
        $closedAt = $ticket->fresh()->closed_at;
        $this->actingAs($operator)->post("/tickets/{$ticket->id}/close")->assertSessionHas('success');

        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertTrue($ticket->fresh()->closed_at->equalTo($closedAt));
        $this->assertSame([], $this->telegram->sentMessages);
    }

    public function test_statistics_are_calculated_from_persisted_data(): void
    {
        $operator = $this->operator();
        $answeredTicket = $this->ticket('Персональный вопрос', Carbon::parse('2026-10-01 06:00:00 UTC'));
        $answeredTicket->update([
            'first_operator_response_at' => Carbon::parse('2026-10-01 06:30:00 UTC'),
        ]);
        $this->ticket('Без ответа', Carbon::parse('2026-10-01 07:00:00 UTC'));
        $this->botResolvedMessage();
        $this->botAnswerWithFailedDelivery();

        $this->actingAs($operator)
            ->get('/statistics')
            ->assertOk()
            ->assertSee('Решено ботом')
            ->assertSee('Передано оператору')
            ->assertSee('Среднее время первого ответа');

        $this->assertSame(1, app(SupportStatistics::class)->allTime()['bot_resolved']);
        $this->assertSame(2, app(SupportStatistics::class)->allTime()['escalated']);
        $this->assertSame(1800.0, app(SupportStatistics::class)->allTime()['average_operator_response_seconds']);
    }

    private function operator(bool $active = true, string $password = 'password'): User
    {
        return User::query()->create([
            'name' => 'Оператор Тест',
            'email' => $this->nextIdentifier().'@example.test',
            'password' => Hash::make($password),
            'is_active' => $active,
        ]);
    }

    private function ticket(
        string $body = 'Почему отклонили чек?',
        ?Carbon $createdAt = null,
    ): SupportTicket {
        $createdAt ??= Carbon::parse('2026-10-01 06:00:00 UTC');
        $participant = TelegramParticipant::query()->create([
            'telegram_chat_id' => $this->nextIdentifier(),
        ]);
        $trigger = $this->participantMessage($participant, $body);
        $ticket = SupportTicket::query()->create([
            'telegram_participant_id' => $participant->id,
            'trigger_message_id' => $trigger->id,
            'status' => 'open',
        ]);

        $ticket->timestamps = false;
        $ticket->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        $ticket->timestamps = true;

        return $ticket->refresh();
    }

    private function participantMessage(
        TelegramParticipant $participant,
        string $body,
        ?SupportTicket $ticket = null,
    ): Message {
        return Message::query()->create([
            'telegram_participant_id' => $participant->id,
            'support_ticket_id' => $ticket?->id,
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

    private function botResolvedMessage(): void
    {
        $this->botAnswer('sent');
    }

    private function botAnswerWithFailedDelivery(): void
    {
        $this->botAnswer('failed');
    }

    private function botAnswer(string $deliveryStatus): void
    {
        $participant = TelegramParticipant::query()->create([
            'telegram_chat_id' => $this->nextIdentifier(),
        ]);
        $incoming = $this->participantMessage($participant, 'Вопрос по правилам');
        $decision = BotDecision::query()->create([
            'incoming_message_id' => $incoming->id,
            'action' => 'answer',
            'business_reason' => 'grounded_in_rules',
            'technical_outcome' => 'valid',
            'rule_references' => ['2.3'],
            'provider' => 'test',
            'model' => 'test',
            'prompt_version' => 'bot-v2',
            'rules_hash' => str_repeat('a', 64),
            'decided_at' => now(),
        ]);
        Message::query()->create([
            'telegram_participant_id' => $participant->id,
            'support_ticket_id' => null,
            'operator_user_id' => null,
            'bot_decision_id' => $decision->id,
            'author_type' => 'bot',
            'body' => 'Ответ по правилам',
            'telegram_update_id' => null,
            'telegram_message_id' => $deliveryStatus === 'sent' ? $this->nextIdentifier() : null,
            'delivery_status' => $deliveryStatus,
            'telegram_sent_at' => $deliveryStatus === 'sent' ? now() : null,
            'delivery_error' => $deliveryStatus === 'failed' ? 'Temporary failure.' : null,
            'delivery_attempt_count' => 1,
        ]);
    }

    private function nextIdentifier(): int
    {
        return ++$this->identifier;
    }
}
