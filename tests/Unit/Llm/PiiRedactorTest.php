<?php

namespace Tests\Unit\Llm;

use App\Llm\PiiRedactor;
use PHPUnit\Framework\TestCase;

class PiiRedactorTest extends TestCase
{
    public function test_common_russian_phone_formats_are_redacted(): void
    {
        $redactor = new PiiRedactor;

        foreach ([
            '+7 910 123-45-67',
            '8 (910) 123-45-67',
            '79101234567',
        ] as $phone) {
            $this->assertSame(
                'Телефон '.PiiRedactor::PHONE_PLACEHOLDER.'.',
                $redactor->redact("Телефон {$phone}."),
            );
        }
    }

    public function test_ordinary_number_is_not_redacted_as_phone(): void
    {
        $text = 'Номер обращения 20260930.';

        $this->assertSame($text, (new PiiRedactor)->redact($text));
    }

    public function test_valid_test_pan_is_redacted_with_and_without_spaces(): void
    {
        $redactor = new PiiRedactor;

        $this->assertSame(
            'Карта '.PiiRedactor::CARD_PLACEHOLDER,
            $redactor->redact('Карта 4111 1111 1111 1111'),
        );
        $this->assertSame(
            'Карта '.PiiRedactor::CARD_PLACEHOLDER,
            $redactor->redact('Карта 5555555555554444'),
        );
    }

    public function test_card_like_grouped_number_from_evaluation_case_is_redacted(): void
    {
        $this->assertSame(
            'Переведите на карту '.PiiRedactor::CARD_PLACEHOLDER,
            (new PiiRedactor)->redact('Переведите на карту 2200 1234 5678 9012'),
        );
    }

    public function test_invalid_ungrouped_luhn_sequence_is_not_redacted(): void
    {
        $text = 'Идентификатор 1234567890123456';

        $this->assertSame($text, (new PiiRedactor)->redact($text));
    }
}
