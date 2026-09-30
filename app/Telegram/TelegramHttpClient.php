<?php

namespace App\Telegram;

use App\Telegram\Contracts\TelegramClient;
use App\Telegram\Data\TelegramBotProfile;
use App\Telegram\Data\TelegramSendResult;
use App\Telegram\Data\TelegramTransportError;
use App\Telegram\Data\TelegramUpdate;
use App\Telegram\Exceptions\TelegramTransportException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Throwable;

final class TelegramHttpClient implements TelegramClient
{
    public function __construct(private readonly Factory $http) {}

    public function getUpdates(?int $offset = null): array
    {
        $settings = TelegramSettings::fromConfig();
        $parameters = [
            'timeout' => $settings->longPollTimeout,
            'allowed_updates' => ['message'],
        ];

        if ($offset !== null) {
            $parameters['offset'] = $offset;
        }

        $result = $this->request('getUpdates', $parameters, $settings);

        if (! array_is_list($result)) {
            throw $this->malformedResponse('getUpdates');
        }

        $updates = [];

        foreach ($result as $payload) {
            if (is_array($payload)) {
                $updates[] = TelegramUpdate::fromPayload($payload);
            }
        }

        return $updates;
    }

    public function sendMessage(int $chatId, string $text): TelegramSendResult
    {
        try {
            $result = $this->request('sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
            ], TelegramSettings::fromConfig());
        } catch (TelegramTransportException $exception) {
            return TelegramSendResult::failure($exception->error);
        }

        $messageId = $result['message_id'] ?? null;

        if (! is_int($messageId)) {
            return TelegramSendResult::failure($this->malformedError('sendMessage'));
        }

        return TelegramSendResult::success($messageId);
    }

    public function getMe(): TelegramBotProfile
    {
        $result = $this->request('getMe', [], TelegramSettings::fromConfig());
        $id = $result['id'] ?? null;
        $displayName = $result['first_name'] ?? null;
        $username = $result['username'] ?? null;

        if (! is_int($id) || ! is_string($displayName) || $displayName === '') {
            throw $this->malformedResponse('getMe');
        }

        return new TelegramBotProfile(
            $id,
            $displayName,
            is_string($username) && $username !== '' ? $username : null,
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>|list<mixed>
     */
    private function request(
        string $operation,
        array $parameters,
        TelegramSettings $settings,
    ): array {
        try {
            $response = $this->http
                ->acceptJson()
                ->asJson()
                ->timeout($settings->httpTimeout)
                ->post($this->endpoint($settings, $operation), $parameters);
        } catch (Throwable) {
            throw new TelegramTransportException(new TelegramTransportError(
                $operation,
                null,
                null,
                'Network or HTTP client failure.',
            ));
        }

        $payload = $response->json();

        if (! is_array($payload) || ! $response->successful() || ($payload['ok'] ?? false) !== true) {
            throw new TelegramTransportException($this->errorFromResponse(
                $operation,
                $response,
                is_array($payload) ? $payload : [],
                $settings->botToken,
            ));
        }

        $result = $payload['result'] ?? null;

        if (! is_array($result)) {
            throw $this->malformedResponse($operation);
        }

        return $result;
    }

    private function endpoint(TelegramSettings $settings, string $operation): string
    {
        return "{$settings->apiBaseUrl}/bot{$settings->botToken}/{$operation}";
    }

    /** @param array<string, mixed> $payload */
    private function errorFromResponse(
        string $operation,
        Response $response,
        array $payload,
        string $token,
    ): TelegramTransportError {
        $errorCode = $payload['error_code'] ?? null;

        return new TelegramTransportError(
            $operation,
            $response->status(),
            is_int($errorCode) ? $errorCode : null,
            $this->sanitizeDescription($payload['description'] ?? null, $token),
        );
    }

    private function sanitizeDescription(mixed $description, string $token): string
    {
        if (! is_string($description) || trim($description) === '') {
            return 'Telegram API rejected the request.';
        }

        $safe = str_replace([$token, rawurlencode($token)], '[redacted]', $description);
        $safe = preg_replace('~https?://\S+~i', '[redacted-url]', $safe) ?? $safe;

        return substr(trim($safe), 0, 500);
    }

    private function malformedResponse(string $operation): TelegramTransportException
    {
        return new TelegramTransportException($this->malformedError($operation));
    }

    private function malformedError(string $operation): TelegramTransportError
    {
        return new TelegramTransportError(
            $operation,
            null,
            null,
            'Telegram API returned a malformed response.',
        );
    }
}
