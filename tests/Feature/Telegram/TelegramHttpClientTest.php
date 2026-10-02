<?php

namespace Tests\Feature\Telegram;

use App\Telegram\Contracts\TelegramClient;
use App\Telegram\Exceptions\TelegramConfigurationException;
use App\Telegram\Exceptions\TelegramTransportException;
use App\Telegram\TelegramSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramHttpClientTest extends TestCase
{
    private const TOKEN = '123456789:fake-test-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('telegram', [
            'bot_token' => self::TOKEN,
            'api_base_url' => 'https://telegram.test',
            'long_poll_timeout' => 17,
            'http_timeout' => 25,
            'poll_backoff_seconds' => 1,
        ]);
    }

    public function test_get_updates_sends_offset_timeout_and_allowed_update_type(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => [['update_id' => 101, 'message' => []]],
            ]),
        ]);

        $updates = $this->client()->getUpdates(101);

        $this->assertCount(1, $updates);
        $this->assertSame(101, $updates[0]->updateId);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://telegram.test/bot'.self::TOKEN.'/getUpdates'
            && $request['offset'] === 101
            && $request['timeout'] === 17
            && $request['allowed_updates'] === ['message']
        );
    }

    public function test_send_message_sends_expected_data_and_returns_message_id(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 404],
            ]),
        ]);

        $result = $this->client()->sendMessage(987654, 'Точный исходный текст');

        $this->assertTrue($result->successful);
        $this->assertSame(404, $result->messageId);
        $this->assertNull($result->error);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === 987654
            && $request['text'] === 'Точный исходный текст'
        );
    }

    public function test_send_message_api_error_returns_safe_result_without_token_or_url(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Rejected '.
                    'https://telegram.test/bot'.self::TOKEN.'/sendMessage',
            ], 400),
        ]);

        $result = $this->client()->sendMessage(123, 'Message');

        $this->assertFalse($result->successful);
        $this->assertNull($result->messageId);
        $this->assertNotNull($result->error);
        $this->assertStringNotContainsString(self::TOKEN, $result->error->publicMessage());
        $this->assertStringNotContainsString('https://telegram.test', $result->error->publicMessage());
        $this->assertSame(400, $result->error->httpStatus);
        $this->assertSame(400, $result->error->apiErrorCode);
    }

    public function test_get_me_returns_minimal_bot_profile(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => [
                    'id' => 42,
                    'is_bot' => true,
                    'first_name' => 'Autumn Support',
                    'username' => 'autumn_support_bot',
                    'can_join_groups' => true,
                ],
            ]),
        ]);

        $bot = $this->client()->getMe();

        $this->assertSame(42, $bot->id);
        $this->assertSame('Autumn Support', $bot->displayName);
        $this->assertSame('autumn_support_bot', $bot->username);
    }

    public function test_get_me_error_throws_safe_exception_without_token(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => false,
                'error_code' => 401,
                'description' => 'Unauthorized for '.self::TOKEN,
            ], 401),
        ]);

        try {
            $this->client()->getMe();
            $this->fail('A transport exception was not thrown.');
        } catch (TelegramTransportException $exception) {
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            $this->assertStringContainsString('getMe', $exception->getMessage());
            $this->assertSame(401, $exception->error->httpStatus);
        }
    }

    public function test_http_layer_exception_cannot_expose_token_or_request_url(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException(
                'Connection failed for https://telegram.test/bot'.self::TOKEN.'/getUpdates',
            );
        });

        try {
            $this->client()->getUpdates();
            $this->fail('A transport exception was not thrown.');
        } catch (TelegramTransportException $exception) {
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            $this->assertStringNotContainsString('https://telegram.test', $exception->getMessage());
            $this->assertStringContainsString('Network or HTTP client failure', $exception->getMessage());
        }
    }

    public function test_http_timeout_must_exceed_long_poll_timeout(): void
    {
        config()->set('telegram.http_timeout', 17);

        $this->expectException(TelegramConfigurationException::class);

        TelegramSettings::fromConfig();
    }

    private function client(): TelegramClient
    {
        return $this->app->make(TelegramClient::class);
    }
}
