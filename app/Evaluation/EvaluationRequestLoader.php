<?php

namespace App\Evaluation;

use RuntimeException;

final class EvaluationRequestLoader
{
    /** @return array<int, string> */
    public function load(): array
    {
        $path = base_path('docs/requests.md');
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Evaluation requests file cannot be read.');
        }

        preg_match_all(
            '/^\*\*(\d+)\.\*\*\s+(.+?)(?=\R\R\*\*\d+\.|\z)/msu',
            $contents,
            $matches,
            PREG_SET_ORDER,
        );

        $requests = [];

        foreach ($matches as $match) {
            $requests[(int) $match[1]] = trim($match[2]);
        }

        if (array_keys($requests) !== range(1, 25)) {
            throw new RuntimeException('Evaluation dataset must contain cases 1 through 25.');
        }

        return $requests;
    }
}
