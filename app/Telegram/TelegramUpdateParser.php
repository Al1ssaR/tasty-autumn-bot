<?php

namespace App\Telegram;

use App\Telegram\Data\IncomingTelegramMessage;
use App\Telegram\Data\ParsedTelegramUpdate;
use App\Telegram\Data\TelegramUpdate;

final class TelegramUpdateParser
{
    /** @var list<string> */
    private const UNSUPPORTED_ATTACHMENTS = [
        'photo',
        'document',
        'voice',
        'video',
        'sticker',
    ];

    public function parse(TelegramUpdate $update): ParsedTelegramUpdate
    {
        $message = $update->payload['message'] ?? null;

        if ($update->updateId === null || ! is_array($message)) {
            return ParsedTelegramUpdate::ignored($update->updateId);
        }

        $chat = $message['chat'] ?? null;

        if (! is_array($chat) || ($chat['type'] ?? null) !== 'private') {
            return ParsedTelegramUpdate::ignored($update->updateId);
        }

        $chatId = $chat['id'] ?? null;

        if (! is_int($chatId)) {
            return ParsedTelegramUpdate::ignored($update->updateId);
        }

        $text = $message['text'] ?? null;
        $messageId = $message['message_id'] ?? null;

        if (is_string($text) && trim($text) !== '' && is_int($messageId)) {
            return ParsedTelegramUpdate::incoming(new IncomingTelegramMessage(
                $update->updateId,
                $chatId,
                $messageId,
                $text,
            ));
        }

        foreach (self::UNSUPPORTED_ATTACHMENTS as $attachment) {
            if (array_key_exists($attachment, $message)) {
                return ParsedTelegramUpdate::unsupported($update->updateId, $chatId);
            }
        }

        return ParsedTelegramUpdate::ignored($update->updateId);
    }
}
