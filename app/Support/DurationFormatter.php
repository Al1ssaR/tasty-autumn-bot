<?php

namespace App\Support;

final class DurationFormatter
{
    public function formatSeconds(float $seconds): string
    {
        $remaining = max(0, (int) round($seconds));
        $parts = [];

        foreach ([
            'д' => 86_400,
            'ч' => 3_600,
            'мин' => 60,
            'сек' => 1,
        ] as $label => $unitSeconds) {
            $value = intdiv($remaining, $unitSeconds);
            $remaining %= $unitSeconds;

            if ($value > 0) {
                $parts[] = "{$value} {$label}";
            }
        }

        return $parts === [] ? '0 сек' : implode(' ', array_slice($parts, 0, 3));
    }
}
