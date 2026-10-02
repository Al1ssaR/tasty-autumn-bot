<?php

namespace Tests\Unit\Telegram;

use App\Telegram\Data\TelegramUpdate;
use App\Telegram\Enums\TelegramUpdateDisposition;
use App\Telegram\TelegramUpdateParser;
use PHPUnit\Framework\TestCase;

class TelegramUpdateParserTest extends TestCase
{
    public function test_private_text_message_is_normalized_without_changing_text(): void
    {
        $text = '  ВЫ МОШЕННИКИ!!!  ';
        $parsed = $this->parser()->parse(TelegramUpdate::fromPayload([
            'update_id' => 501,
            'message' => [
                'message_id' => 77,
                'chat' => ['id' => 123456, 'type' => 'private'],
                'text' => $text,
            ],
        ]));

        $this->assertSame(TelegramUpdateDisposition::IncomingText, $parsed->disposition);
        $this->assertNotNull($parsed->incomingMessage);
        $this->assertSame(501, $parsed->incomingMessage->updateId);
        $this->assertSame(123456, $parsed->incomingMessage->chatId);
        $this->assertSame(77, $parsed->incomingMessage->messageId);
        $this->assertSame($text, $parsed->incomingMessage->text);
    }

    public function test_non_message_updates_are_ignored(): void
    {
        $payloads = [
            ['update_id' => 1, 'callback_query' => ['id' => 'callback']],
            ['update_id' => 2, 'edited_message' => ['text' => 'edited']],
            ['update_id' => 3, 'channel_post' => ['text' => 'channel']],
        ];

        foreach ($payloads as $payload) {
            $parsed = $this->parser()->parse(TelegramUpdate::fromPayload($payload));
            $this->assertSame(TelegramUpdateDisposition::Ignored, $parsed->disposition);
        }
    }

    public function test_non_private_message_is_ignored(): void
    {
        $parsed = $this->parser()->parse(TelegramUpdate::fromPayload([
            'update_id' => 4,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => -100, 'type' => 'group'],
                'text' => 'Question',
            ],
        ]));

        $this->assertSame(TelegramUpdateDisposition::Ignored, $parsed->disposition);
    }

    public function test_private_attachment_is_marked_unsupported_not_for_classification(): void
    {
        foreach (['photo', 'document', 'voice', 'video', 'sticker'] as $attachment) {
            $parsed = $this->parser()->parse(TelegramUpdate::fromPayload([
                'update_id' => 5,
                'message' => [
                    'message_id' => 2,
                    'chat' => ['id' => 555, 'type' => 'private'],
                    $attachment => ['file_id' => 'not-downloaded'],
                ],
            ]));

            $this->assertSame(
                TelegramUpdateDisposition::UnsupportedPrivateMessage,
                $parsed->disposition,
            );
            $this->assertSame(555, $parsed->unsupportedChatId);
            $this->assertNull($parsed->incomingMessage);
        }
    }

    public function test_malformed_payload_is_safely_ignored(): void
    {
        $payloads = [
            [],
            ['update_id' => 'invalid', 'message' => 'invalid'],
            ['update_id' => 6, 'message' => ['chat' => ['type' => 'private']]],
            ['update_id' => 7, 'message' => ['chat' => ['id' => 7, 'type' => 'private'], 'text' => '   ']],
        ];

        foreach ($payloads as $payload) {
            $parsed = $this->parser()->parse(TelegramUpdate::fromPayload($payload));
            $this->assertSame(TelegramUpdateDisposition::Ignored, $parsed->disposition);
        }
    }

    private function parser(): TelegramUpdateParser
    {
        return new TelegramUpdateParser;
    }
}
