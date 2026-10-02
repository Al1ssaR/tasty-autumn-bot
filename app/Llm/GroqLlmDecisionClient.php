<?php

namespace App\Llm;

use App\Llm\Contracts\LlmDecisionClient;
use App\Llm\Data\LlmClientIdentity;
use App\Llm\Data\LlmDecisionRequest;
use App\Llm\Data\LlmRawResponse;
use App\Llm\Exceptions\LlmApiException;
use App\Llm\Exceptions\LlmRateLimitException;
use App\Llm\Exceptions\LlmTimeoutException;
use App\Llm\Exceptions\MalformedLlmResponseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use JsonException;
use Throwable;

final class GroqLlmDecisionClient implements LlmDecisionClient
{
    public function __construct(private readonly Factory $http) {}

    public function identity(): LlmClientIdentity
    {
        return new LlmClientIdentity(
            $this->nullableConfigString('llm.provider'),
            $this->nullableConfigString('llm.model'),
        );
    }

    public function decide(LlmDecisionRequest $request): LlmRawResponse
    {
        $apiKey = $this->requiredConfigString('llm.api_key', 'LLM_API_KEY');
        $baseUrl = $this->requiredConfigString('llm.base_url', 'LLM_BASE_URL');
        $model = $this->requiredConfigString('llm.model', 'LLM_MODEL');
        $reasoningEffort = $this->requiredConfigString(
            'llm.reasoning_effort',
            'LLM_REASONING_EFFORT',
        );
        $timeout = config('llm.timeout');

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new LlmApiException('LLM_BASE_URL must be a valid URL.');
        }

        if (! is_int($timeout) || $timeout < 1) {
            throw new LlmApiException('LLM_TIMEOUT must be a positive integer.');
        }

        try {
            $response = $this->http
                ->acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->timeout($timeout)
                ->post(rtrim($baseUrl, '/').'/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $this->systemContent($request),
                        ],
                        [
                            'role' => 'user',
                            'content' => $request->userMessage,
                        ],
                    ],
                    'reasoning_effort' => $reasoningEffort,
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'support_decision',
                            'strict' => true,
                            'schema' => $request->responseSchema,
                        ],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            if ($this->isTimeout($exception)) {
                throw new LlmTimeoutException('LLM provider request timed out.');
            }

            throw new LlmApiException('LLM provider connection failed.');
        } catch (LlmApiException|LlmTimeoutException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LlmApiException('LLM provider request failed.');
        }

        if ($response->status() === 429) {
            throw new LlmRateLimitException($this->retryAfterSeconds($response));
        }

        if (! $response->successful()) {
            if ($response->status() === 400 && $this->isSchemaRejection($response)) {
                throw new LlmApiException(
                    'LLM provider rejected the strict response schema (HTTP 400).',
                );
            }

            throw new LlmApiException(sprintf(
                'LLM provider request failed with HTTP status %d.',
                $response->status(),
            ));
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new MalformedLlmResponseException(
                'LLM provider response does not contain assistant content.',
            );
        }

        try {
            $decoded = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MalformedLlmResponseException(
                'LLM provider assistant content is not valid JSON.',
                previous: $exception,
            );
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new MalformedLlmResponseException(
                'LLM provider assistant content must be a JSON object.',
            );
        }

        return new LlmRawResponse($content);
    }

    private function systemContent(LlmDecisionRequest $request): string
    {
        return implode("\n\n", [
            trim($request->systemPrompt),
            '# Доверенный runtime-контекст',
            "Текущее время акции: {$request->currentTime}",
            "## Правила акции\n".trim($request->rules),
        ]);
    }

    private function requiredConfigString(string $key, string $environmentName): string
    {
        $value = $this->nullableConfigString($key);

        if ($value === null) {
            throw new LlmApiException("{$environmentName} is not configured.");
        }

        return $value;
    }

    private function nullableConfigString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function isTimeout(ConnectionException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'curl error 28')
            || str_contains($message, 'timed out')
            || str_contains($message, 'timeout');
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $header = trim($response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return min((int) $header, 300);
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : min(max(0, $timestamp - time()), 300);
    }

    private function isSchemaRejection(Response $response): bool
    {
        $message = $response->json('error.message');

        if (! is_string($message)) {
            return false;
        }

        $message = strtolower($message);

        return str_contains($message, 'schema')
            || str_contains($message, 'response_format');
    }
}
