<?php

namespace App\Evaluation;

use RuntimeException;

final class RoutingEvaluationDataset
{
    /**
     * @return array<int, array{category: string, text: string, action: string, reason: string}>
     */
    public function load(): array
    {
        $cases = require base_path('evaluation/routing-cases.php');

        if (! is_array($cases) || array_keys($cases) !== range(1, 10)) {
            throw new RuntimeException('Routing dataset must contain cases 1 through 10.');
        }

        foreach ($cases as $case) {
            if (! is_array($case)
                || array_keys($case) !== ['category', 'text', 'action', 'reason']
                || ! is_string($case['category'])
                || ! is_string($case['text'])
                || ! is_string($case['action'])
                || ! is_string($case['reason'])
                || trim($case['category']) === ''
                || trim($case['text']) === '') {
                throw new RuntimeException('Routing dataset contains an invalid case.');
            }
        }

        return $cases;
    }
}
