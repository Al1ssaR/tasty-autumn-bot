<?php

namespace App\Telegram;

use App\Telegram\Contracts\IncomingMessageIntake;
use App\Telegram\Contracts\TelegramClient;
use App\Telegram\Data\TelegramPollingBatchResult;
use App\Telegram\Data\TelegramTransportError;
use App\Telegram\Data\TelegramUpdate;
use App\Telegram\Enums\TelegramUpdateDisposition;
use App\Telegram\Exceptions\TelegramTransportException;

final class TelegramPollingWorker
{
    public const UNSUPPORTED_MESSAGE_REPLY = 'Пожалуйста, отправьте вопрос текстовым сообщением.';

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramUpdateParser $parser,
        private readonly IncomingMessageIntake $intake,
    ) {}

    public function pollOnce(?int $offset = null): TelegramPollingBatchResult
    {
        $updates = $this->client->getUpdates($offset);

        usort($updates, static function (TelegramUpdate $left, TelegramUpdate $right): int {
            if ($left->updateId === null) {
                return $right->updateId === null ? 0 : 1;
            }

            if ($right->updateId === null) {
                return -1;
            }

            return $left->updateId <=> $right->updateId;
        });

        $nextOffset = $offset;
        $ignored = 0;
        $unsupported = 0;
        $statuses = [];

        foreach ($updates as $update) {
            $parsed = $this->parser->parse($update);

            if ($parsed->disposition === TelegramUpdateDisposition::IncomingText) {
                $statuses[] = $this->intake->handle($parsed->incomingMessage)->status;
            } elseif ($parsed->disposition === TelegramUpdateDisposition::UnsupportedPrivateMessage) {
                $unsupported++;
                $result = $this->client->sendMessage(
                    $parsed->unsupportedChatId,
                    self::UNSUPPORTED_MESSAGE_REPLY,
                );

                if (! $result->successful) {
                    throw new TelegramTransportException(
                        $result->error ?? new TelegramTransportError(
                            'sendMessage',
                            null,
                            null,
                            'Telegram send failed without an error result.',
                        ),
                    );
                }
            } else {
                $ignored++;
            }

            if ($parsed->updateId !== null) {
                $nextOffset = max($nextOffset ?? 0, $parsed->updateId + 1);
            }
        }

        return new TelegramPollingBatchResult(
            $nextOffset,
            count($updates),
            $ignored,
            $unsupported,
            $statuses,
        );
    }
}
