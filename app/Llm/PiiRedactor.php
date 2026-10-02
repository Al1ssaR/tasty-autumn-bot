<?php

namespace App\Llm;

final class PiiRedactor
{
    public const PHONE_PLACEHOLDER = '[PHONE_REDACTED]';

    public const CARD_PLACEHOLDER = '[CARD_REDACTED]';

    private const CARD_CANDIDATE_PATTERN = '/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/u';

    private const GROUPED_CARD_PATTERN = '/^\d{4}(?:[ -]\d{4}){3}$/';

    private const PHONE_PATTERN = '/(?<!\d)(?:\+7|[78])[\s-]*\(?\d{3}\)?[\s-]*\d{3}[\s-]*\d{2}[\s-]*\d{2}(?!\d)/u';

    public function redact(string $text): string
    {
        $withoutCards = preg_replace_callback(
            self::CARD_CANDIDATE_PATTERN,
            function (array $matches): string {
                $candidate = $matches[0];
                $digits = preg_replace('/\D/', '', $candidate) ?? '';

                if ($this->passesLuhn($digits)
                    || preg_match(self::GROUPED_CARD_PATTERN, $candidate) === 1) {
                    return self::CARD_PLACEHOLDER;
                }

                return $candidate;
            },
            $text,
        ) ?? $text;

        return preg_replace(
            self::PHONE_PATTERN,
            self::PHONE_PLACEHOLDER,
            $withoutCards,
        ) ?? $withoutCards;
    }

    private function passesLuhn(string $digits): bool
    {
        $length = strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;
        $parity = $length % 2;

        for ($index = 0; $index < $length; $index++) {
            $digit = (int) $digits[$index];

            if ($index % 2 === $parity) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
