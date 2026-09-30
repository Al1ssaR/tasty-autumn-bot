<?php

namespace Tests\Feature\Telegram;

use App\Telegram\Contracts\IncomingMessageIntake;
use App\Telegram\Contracts\TelegramClient;
use App\Telegram\Data\TelegramTransportError;
use App\Telegram\Data\TelegramUpdate;
use App\Telegram\Enums\IncomingMessageIntakeStatus;
use App\Telegram\Exceptions\TelegramTransportException;
use App\Telegram\TelegramPollingWorker;
use App\Telegram\TelegramUpdateParser;
use Illuminate\Support\Facades\Artisan;
use Tests\Fakes\FakeIncomingMessageIntake;
use Tests\Fakes\FakeTelegramClient;
use Tests\TestCase;

class TelegramPollingWorkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('telegram', [
            'bot_token' => '123456789:fake-polling-token',
            'api_base_url' => 'https://telegram.test',
            'long_poll_timeout' => 1,
            'http_timeout' => 2,
            'poll_backoff_seconds' => 1,
        ]);
    }

    public function test_batch_is_processed_in_update_id_order_and_returns_next_offset(): void
    {
        $client = new FakeTelegramClient;
        $intake = new FakeIncomingMessageIntake;
        $client->updateBatches = [[
            $this->textUpdate(12, 120),
            $this->textUpdate(10, 100),
            $this->textUpdate(11, 110),
        ]];

        $result = $this->worker($client, $intake)->pollOnce(10);

        $this->assertSame([10], $client->requestedOffsets);
        $this->assertSame([10, 11, 12], array_map(
            fn ($message): int => $message->updateId,
            $intake->received,
        ));
        $this->assertSame(13, $result->nextOffset);
        $this->assertSame(3, $result->receivedUpdates);
    }

    public function test_duplicate_result_is_safe_and_does_not_stop_next_update(): void
    {
        $client = new FakeTelegramClient;
        $intake = new FakeIncomingMessageIntake;
        $intake->duplicateUpdateIds = [20];
        $client->updateBatches = [[$this->textUpdate(20), $this->textUpdate(21)]];

        $result = $this->worker($client, $intake)->pollOnce();

        $this->assertSame([
            IncomingMessageIntakeStatus::DuplicateUpdate,
            IncomingMessageIntakeStatus::ReadyForClassification,
        ], $result->intakeStatuses);
        $this->assertSame(22, $result->nextOffset);
    }

    public function test_malformed_update_does_not_stop_following_valid_update(): void
    {
        $client = new FakeTelegramClient;
        $intake = new FakeIncomingMessageIntake;
        $client->updateBatches = [[
            TelegramUpdate::fromPayload(['update_id' => 30, 'message' => 'malformed']),
            $this->textUpdate(31),
        ]];

        $result = $this->worker($client, $intake)->pollOnce();

        $this->assertSame([31], array_map(
            fn ($message): int => $message->updateId,
            $intake->received,
        ));
        $this->assertSame(1, $result->ignoredUpdates);
        $this->assertSame(32, $result->nextOffset);
    }

    public function test_unsupported_private_attachment_gets_static_reply_without_intake(): void
    {
        $client = new FakeTelegramClient;
        $intake = new FakeIncomingMessageIntake;
        $client->updateBatches = [[TelegramUpdate::fromPayload([
            'update_id' => 40,
            'message' => [
                'message_id' => 4,
                'chat' => ['id' => 444, 'type' => 'private'],
                'voice' => ['file_id' => 'not-downloaded'],
            ],
        ])]];

        $result = $this->worker($client, $intake)->pollOnce();

        $this->assertSame([], $intake->received);
        $this->assertSame([[
            'chat_id' => 444,
            'text' => TelegramPollingWorker::UNSUPPORTED_MESSAGE_REPLY,
        ]], $client->sentMessages);
        $this->assertSame(1, $result->unsupportedUpdates);
        $this->assertSame(41, $result->nextOffset);
    }

    public function test_single_iteration_command_completes_after_one_batch(): void
    {
        $client = new FakeTelegramClient;
        $intake = new FakeIncomingMessageIntake;
        $client->updateBatches = [[]];
        $this->app->instance(TelegramClient::class, $client);
        $this->app->instance(IncomingMessageIntake::class, $intake);

        $exitCode = Artisan::call('telegram:poll', ['--once' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertCount(1, $client->requestedOffsets);
        $this->assertStringContainsString('batch completed', Artisan::output());
    }

    public function test_single_iteration_transport_error_fails_safely(): void
    {
        $client = new FakeTelegramClient;
        $intake = new FakeIncomingMessageIntake;
        $client->getUpdatesException = new TelegramTransportException(
            new TelegramTransportError('getUpdates', 503, null, 'Temporary failure.'),
        );
        $this->app->instance(TelegramClient::class, $client);
        $this->app->instance(IncomingMessageIntake::class, $intake);

        $exitCode = Artisan::call('telegram:poll', ['--once' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Temporary failure', Artisan::output());
        $this->assertStringNotContainsString('fake-polling-token', Artisan::output());
    }

    private function worker(
        FakeTelegramClient $client,
        FakeIncomingMessageIntake $intake,
    ): TelegramPollingWorker {
        return new TelegramPollingWorker($client, new TelegramUpdateParser, $intake);
    }

    private function textUpdate(int $updateId, ?int $messageId = null): TelegramUpdate
    {
        return TelegramUpdate::fromPayload([
            'update_id' => $updateId,
            'message' => [
                'message_id' => $messageId ?? $updateId,
                'chat' => ['id' => 9000 + $updateId, 'type' => 'private'],
                'text' => "Question {$updateId}",
            ],
        ]);
    }
}
