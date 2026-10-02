<?php

namespace Tests\Feature\Llm;

use App\Llm\Contracts\LlmDecisionClient;
use App\Llm\Data\LlmDecisionRequest;
use App\Llm\Exceptions\LlmApiException;
use App\Llm\Exceptions\LlmRateLimitException;
use App\Llm\Exceptions\LlmTimeoutException;
use App\Llm\Exceptions\MalformedLlmResponseException;
use App\Llm\GroqLlmDecisionClient;
use App\Llm\UnavailableLlmDecisionClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroqLlmDecisionClientTest extends TestCase
{
    private const API_KEY = 'test-groq-key-never-expose';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'llm.provider' => 'groq',
            'llm.model' => 'openai/gpt-oss-120b',
            'llm.api_key' => self::API_KEY,
            'llm.base_url' => 'https://groq.test/openai/v1',
            'llm.timeout' => 30,
            'llm.reasoning_effort' => 'medium',
        ]);
    }

    public function test_it_sends_the_trusted_context_and_strict_runtime_schema(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'action' => 'answer',
                            'reason' => 'grounded_in_rules',
                            'answer' => 'До 2 ноября.',
                            'rule_references' => ['2.3'],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ]),
        ]);

        $request = $this->decisionRequest();
        $response = $this->client()->decide($request);

        $this->assertJson($response->content);
        $this->assertSame('groq', $this->client()->identity()->provider);
        $this->assertSame('openai/gpt-oss-120b', $this->client()->identity()->model);
        Http::assertSentCount(1);
        /** @var Request $httpRequest */
        $httpRequest = Http::recorded()[0][0];
        $messages = $httpRequest['messages'];

        $this->assertSame('https://groq.test/openai/v1/chat/completions', $httpRequest->url());
        $this->assertTrue($httpRequest->hasHeader('Authorization', 'Bearer '.self::API_KEY));
        $this->assertSame('openai/gpt-oss-120b', $httpRequest['model']);
        $this->assertSame('medium', $httpRequest['reasoning_effort']);
        $this->assertSame('json_schema', $httpRequest['response_format']['type']);
        $this->assertSame('support_decision', $httpRequest['response_format']['json_schema']['name']);
        $this->assertTrue($httpRequest['response_format']['json_schema']['strict']);
        $this->assertSame($request->responseSchema, $httpRequest['response_format']['json_schema']['schema']);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertStringContainsString($request->systemPrompt, $messages[0]['content']);
        $this->assertStringContainsString(trim($request->rules), $messages[0]['content']);
        $this->assertStringContainsString($request->currentTime, $messages[0]['content']);
        $this->assertStringNotContainsString($request->userMessage, $messages[0]['content']);
        $this->assertSame([
            'role' => 'user',
            'content' => $request->userMessage,
        ], $messages[1]);
        $this->assertArrayNotHasKey('tools', $httpRequest->data());
    }

    public function test_http_authentication_failure_is_mapped_without_exposing_secret(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => self::API_KEY]], 401)]);

        $this->assertSafeApiFailure(401);
    }

    public function test_http_authorization_failure_is_mapped_without_exposing_secret(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => self::API_KEY]], 403)]);

        $this->assertSafeApiFailure(403);
    }

    public function test_server_failure_is_mapped_without_exposing_response_body(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => self::API_KEY]], 500)]);

        $this->assertSafeApiFailure(500);
    }

    public function test_schema_rejection_is_identified_without_exposing_provider_body(): void
    {
        Http::fake(['*' => Http::response([
            'error' => [
                'message' => 'Unsupported schema keyword. '.self::API_KEY,
            ],
        ], 400)]);

        try {
            $this->client()->decide($this->decisionRequest());
            $this->fail('An API exception was not thrown.');
        } catch (LlmApiException $exception) {
            $this->assertSame(
                'LLM provider rejected the strict response schema (HTTP 400).',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }
    }

    public function test_rate_limit_exposes_only_a_bounded_retry_delay(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '17'])]);

        try {
            $this->client()->decide($this->decisionRequest());
            $this->fail('A rate-limit exception was not thrown.');
        } catch (LlmRateLimitException $exception) {
            $this->assertSame(17, $exception->retryAfterSeconds);
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }
    }

    public function test_network_failure_is_mapped_without_exposing_secret_or_url(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException(
                'Connection failed using '.self::API_KEY.' at https://groq.test/openai/v1/chat/completions',
            );
        });

        try {
            $this->client()->decide($this->decisionRequest());
            $this->fail('An API exception was not thrown.');
        } catch (LlmApiException $exception) {
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
            $this->assertStringNotContainsString('https://groq.test', $exception->getMessage());
            $this->assertSame('LLM provider connection failed.', $exception->getMessage());
        }
    }

    public function test_timeout_is_mapped_to_the_existing_timeout_exception(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('cURL error 28: request timed out with '.self::API_KEY);
        });

        try {
            $this->client()->decide($this->decisionRequest());
            $this->fail('A timeout exception was not thrown.');
        } catch (LlmTimeoutException $exception) {
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
            $this->assertSame('LLM provider request timed out.', $exception->getMessage());
        }
    }

    public function test_missing_assistant_content_is_malformed(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => []]]])]);

        $this->expectException(MalformedLlmResponseException::class);

        $this->client()->decide($this->decisionRequest());
    }

    public function test_invalid_json_assistant_content_is_malformed(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => 'not-json']]],
        ])]);

        $this->expectException(MalformedLlmResponseException::class);

        $this->client()->decide($this->decisionRequest());
    }

    public function test_container_selects_groq_only_for_the_explicit_provider(): void
    {
        $this->assertInstanceOf(GroqLlmDecisionClient::class, $this->app->make(LlmDecisionClient::class));

        config()->set('llm.provider', 'unknown-provider');

        $this->assertInstanceOf(
            UnavailableLlmDecisionClient::class,
            $this->app->make(LlmDecisionClient::class),
        );
    }

    private function assertSafeApiFailure(int $status): void
    {
        try {
            $this->client()->decide($this->decisionRequest());
            $this->fail('An API exception was not thrown.');
        } catch (LlmApiException $exception) {
            $this->assertStringContainsString((string) $status, $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }
    }

    private function client(): GroqLlmDecisionClient
    {
        return $this->app->make(GroqLlmDecisionClient::class);
    }

    private function decisionRequest(): LlmDecisionRequest
    {
        return new LlmDecisionRequest(
            'Trusted system prompt.',
            "2.3. Registration rule.\n",
            '2026-09-30T12:00:00+03:00 [Europe/Moscow]',
            'Untrusted participant text.',
            [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['answer'],
                'properties' => ['answer' => ['type' => 'string']],
            ],
            'bot-v1',
            hash('sha256', "2.3. Registration rule.\n"),
        );
    }
}
