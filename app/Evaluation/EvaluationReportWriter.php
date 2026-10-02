<?php

namespace App\Evaluation;

use JsonException;
use RuntimeException;

final class EvaluationReportWriter
{
    /** @param list<array<string, mixed>> $results */
    public function write(array $results): void
    {
        if ($results === []) {
            throw new RuntimeException('Evaluation results cannot be empty.');
        }

        $version = $this->version($results);
        $results = $this->applyManualReviews($results, $version);
        $summary = $this->summary($results);
        $payload = [
            'run' => [
                'provider' => $results[0]['provider'],
                'model' => $results[0]['model'],
                'reasoning_effort' => (string) config('llm.reasoning_effort'),
                'sampling_parameters' => 'provider defaults; temperature and top_p omitted',
                'strict_json_schema' => true,
                'prompt_version' => $version,
                'rules_hash' => $results[0]['rules_hash'],
                'evaluation_timestamp' => $results[0]['evaluation_timestamp'],
                'frozen_time' => $results[0]['frozen_time'],
            ],
            'summary' => $summary,
            'comparison_with_bot_v1' => $this->comparison($results, $summary, $version),
            'cases' => $results,
        ];

        try {
            $json = json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Evaluation results cannot be encoded.', previous: $exception);
        }

        $paths = $this->artifactPaths($version);

        if (file_put_contents(base_path($paths['json']), $json."\n") === false
            || file_put_contents(base_path($paths['markdown']), $this->markdown($payload)) === false) {
            throw new RuntimeException('Evaluation report cannot be written.');
        }
    }

    /** @return array{json: string, markdown: string, reviews: string} */
    public function artifactPaths(string $version): array
    {
        return match ($version) {
            'bot-v1' => [
                'json' => 'evaluation/results.json',
                'markdown' => 'docs/evaluation-results.md',
                'reviews' => 'evaluation/reviews.php',
            ],
            'bot-v2' => [
                'json' => 'evaluation/results-v2.json',
                'markdown' => 'docs/evaluation-results-v2.md',
                'reviews' => 'evaluation/reviews-v2.php',
            ],
            'bot-v3' => [
                'json' => 'evaluation/results-v3.json',
                'markdown' => 'docs/evaluation-results-v3.md',
                'reviews' => 'evaluation/reviews-v3.php',
            ],
            default => throw new RuntimeException("Unsupported evaluation prompt version: {$version}."),
        };
    }

    /** @param list<array<string, mixed>> $results */
    private function version(array $results): string
    {
        $version = $results[0]['prompt_version'] ?? null;

        if (! is_string($version)) {
            throw new RuntimeException('Evaluation prompt version is missing.');
        }

        foreach ($results as $result) {
            if (($result['prompt_version'] ?? null) !== $version) {
                throw new RuntimeException('One evaluation report cannot mix prompt versions.');
            }
        }

        $this->artifactPaths($version);

        return $version;
    }

    /** @param list<array<string, mixed>> $results */
    private function summary(array $results): array
    {
        $mismatches = [];
        $technicalFailures = 0;

        foreach ($results as $result) {
            if (! $result['exact_match']) {
                $mismatches[] = $result['number'];
            }

            if ($result['technical_failure'] !== null) {
                $technicalFailures++;
            }
        }

        $manualPasses = count(array_filter(
            $results,
            static fn (array $result): bool => $result['pass'] === true,
        ));
        $manualFailures = count(array_filter(
            $results,
            static fn (array $result): bool => $result['pass'] === false,
        ));
        $manualPending = count($results) - $manualPasses - $manualFailures;

        return [
            'total' => count($results),
            'exact_action_reason_matches' => count($results) - count($mismatches),
            'mismatch_numbers' => $mismatches,
            'technical_failures' => $technicalFailures,
            'manual_passes' => $manualPasses,
            'manual_failures' => $manualFailures,
            'manual_pending' => $manualPending,
            'manual_review' => $manualPending === 0 ? 'completed' : 'pending',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>|null
     */
    private function comparison(array $results, array $summary, string $version): ?array
    {
        if ($version !== 'bot-v2') {
            return null;
        }

        $baseline = $this->readPayload(base_path('evaluation/results.json'));
        $baselineCases = [];

        foreach ($baseline['cases'] ?? [] as $case) {
            if (is_array($case) && is_int($case['number'] ?? null)) {
                $baselineCases[$case['number']] = $case;
            }
        }

        if (array_keys($baselineCases) !== range(1, 25)) {
            throw new RuntimeException('Baseline evaluation must contain cases 1 through 25.');
        }

        $corrected = [];
        $regressions = [];
        $currentCases = [];

        foreach ($results as $result) {
            $number = $result['number'];
            $currentCases[$number] = $result;
            $baselinePass = $baselineCases[$number]['pass'] ?? null;
            $currentPass = $result['pass'];

            if ($baselinePass === false && $currentPass === true) {
                $corrected[] = $number;
            }

            if ($baselinePass === true && $currentPass === false) {
                $regressions[] = $number;
            }
        }

        return [
            'bot_v1' => [
                'exact_action_reason_matches' => $baseline['summary']['exact_action_reason_matches'] ?? null,
                'manual_passes' => $baseline['summary']['manual_passes'] ?? null,
                'manual_failures' => $baseline['summary']['manual_failures'] ?? null,
                'technical_failures' => $baseline['summary']['technical_failures'] ?? null,
                'security_cases_passed' => $this->passedCases($baselineCases, [24, 25]),
                'case_22_pii_redaction' => $baselineCases[22]['pii_redaction'] ?? null,
            ],
            'bot_v2' => [
                'exact_action_reason_matches' => $summary['exact_action_reason_matches'],
                'manual_passes' => $summary['manual_passes'],
                'manual_failures' => $summary['manual_failures'],
                'technical_failures' => $summary['technical_failures'],
                'security_cases_passed' => $this->passedCases($currentCases, [24, 25]),
                'case_22_pii_redaction' => $currentCases[22]['pii_redaction'] ?? null,
            ],
            'corrected_baseline_failures' => $corrected,
            'regressions' => $regressions,
            'manual_review' => $summary['manual_review'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function markdown(array $payload): string
    {
        $run = $payload['run'];
        $summary = $payload['summary'];
        $version = $run['prompt_version'];
        $description = match ($version) {
            'bot-v1' => 'Отчёт фиксирует первый последовательный прогон 25 исходных обращений через одну конфигурацию. Prompt по результатам прогона не изменялся.',
            'bot-v2' => 'Отчёт фиксирует последовательный прогон всех 25 исходных обращений после одной обобщаемой итерации `bot-v1 → bot-v2`.',
            'bot-v3' => 'Отчёт фиксирует продуктовую итерацию routing после ручной UX-проверки. Цель изменения — не повышение evaluation score, а исключение ненужных operator tickets.',
        };
        $lines = [
            "# Evaluation `{$version}`",
            '',
            $description,
            '',
            '## Конфигурация',
            '',
            "- Provider: `{$run['provider']}`",
            "- Model: `{$run['model']}`",
            "- Reasoning effort: `{$run['reasoning_effort']}`",
            "- Sampling: {$run['sampling_parameters']}",
            '- Structured Outputs: `json_schema`, `strict: true`',
            "- Prompt version: `{$version}`",
            "- Rules SHA-256: `{$run['rules_hash']}`",
            "- Время запуска: `{$run['evaluation_timestamp']}`",
            "- Frozen business time: `{$run['frozen_time']}` (`Europe/Moscow`)",
            '',
            '## Результаты',
            '',
            '| № | Обращение | Expected | Actual | Фактический ответ | Пункты | Schema / semantic | PASS/FAIL | Ручная оценка | Комментарий |',
            '|---:|---|---|---|---|---|---|---|---|---|',
        ];

        foreach ($payload['cases'] as $result) {
            $actual = $result['actual_action'] === null
                ? '—'
                : $result['actual_action'].' / '.$result['actual_reason'];
            $references = $result['rule_references'] === []
                ? '—'
                : implode(', ', $result['rule_references']);
            $status = $result['pass'] === null
                ? 'PENDING'
                : ($result['pass'] ? 'PASS' : 'FAIL');
            $lines[] = sprintf(
                '| %02d | %s | `%s / %s` | `%s` | %s | %s | `%s / %s` | **%s** | %s | %s |',
                $result['number'],
                $this->cell($result['request']),
                $result['expected_action'],
                $result['expected_reason'],
                $actual,
                $this->cell($result['actual_answer'] === '' ? '—' : $result['actual_answer']),
                $this->cell($references),
                $result['schema_validation'],
                $result['semantic_validation'],
                $status,
                $this->cell($result['manual_assessment']),
                $this->cell($result['comment']),
            );
        }

        $mismatches = $this->numbers($summary['mismatch_numbers']);
        $lines = array_merge($lines, [
            '',
            '## Summary',
            '',
            "- Total: {$summary['total']}.",
            "- Exact action+reason matches: {$summary['exact_action_reason_matches']} / {$summary['total']}.",
            "- Mismatches: {$mismatches}.",
            "- Technical failures: {$summary['technical_failures']}.",
            "- Manual/content PASS: {$summary['manual_passes']} / {$summary['total']}.",
            "- Manual/content FAIL: {$summary['manual_failures']} / {$summary['total']}.",
            "- Manual/content pending: {$summary['manual_pending']} / {$summary['total']}.",
            "- Ручная содержательная проверка: {$summary['manual_review']}.",
        ]);

        if (is_array($payload['comparison_with_bot_v1'])) {
            $comparison = $payload['comparison_with_bot_v1'];
            $v1 = $comparison['bot_v1'];
            $v2 = $comparison['bot_v2'];
            $lines = array_merge($lines, [
                '',
                '## Сравнение `bot-v1` и `bot-v2`',
                '',
                '| Метрика | bot-v1 | bot-v2 |',
                '|---|---:|---:|',
                "| Exact action+reason | {$v1['exact_action_reason_matches']} / 25 | {$v2['exact_action_reason_matches']} / 25 |",
                "| Strict content PASS | {$v1['manual_passes']} / 25 | {$v2['manual_passes']} / 25 |",
                "| Content FAIL | {$v1['manual_failures']} / 25 | {$v2['manual_failures']} / 25 |",
                "| Technical failures | {$v1['technical_failures']} | {$v2['technical_failures']} |",
                "| Security cases PASS | {$v1['security_cases_passed']} / 2 | {$v2['security_cases_passed']} / 2 |",
                "| №22 provider input redaction | {$v1['case_22_pii_redaction']} | {$v2['case_22_pii_redaction']} |",
                '',
                '- Исправленные baseline FAIL ('.count($comparison['corrected_baseline_failures']).'): '.$this->numbers($comparison['corrected_baseline_failures']).'.',
                '- Regressions (PASS в v1 → FAIL в v2, '.count($comparison['regressions']).'): '.$this->numbers($comparison['regressions']).'.',
                '- Ручная проверка v2: '.$comparison['manual_review'].'.',
            ]);
        }

        if ($version === 'bot-v3') {
            $lines = array_merge($lines, [
                '',
                '## Намеренные изменения expected behavior',
                '',
                '- №23: `escalate / missing_rule` → `respond_static / out_of_scope`; рецепт не относится к поддержке акции и не требует оператора.',
                '- №24: `escalate / unsafe_request` → `respond_static / unsafe_request`; prompt injection не создаёт ticket.',
                '- №25: `escalate / unsafe_request` → `respond_static / unsafe_request`; запрос system prompt и промокода не создаёт ticket.',
                '',
                'Это изменение product semantics и evaluation oracle, а не скрытое улучшение score. Артефакты `bot-v1` и `bot-v2` не изменялись.',
            ]);
        }

        $lines = array_merge($lines, [
            '',
            '## Анализ несовпадений и содержательных дефектов',
            '',
        ]);

        foreach ($payload['cases'] as $result) {
            if ($result['pass'] !== false) {
                continue;
            }

            $categories = $result['mismatch_categories'] ?? [];

            if (! is_array($categories) || $categories === []) {
                $technicalFailure = $result['technical_failure'] ?? null;
                $categories = [is_string($technicalFailure) ? $technicalFailure : 'unclassified'];
            }

            $lines[] = sprintf(
                '- **%02d — %s:** %s',
                $result['number'],
                implode(', ', $categories),
                $result['comment'],
            );
        }

        $cases = [];

        foreach ($payload['cases'] as $case) {
            $cases[$case['number']] = $case;
        }

        $lines = array_merge($lines, [
            '',
            '### Security и PII',
            '',
            '- №22: provider input redaction — `'.($cases[22]['pii_redaction'] ?? 'unknown').'`; в artifacts используется `[CARD_REDACTED]`.',
            '- №24: actual — `'.($cases[24]['actual_action'] ?? '—').' / '.($cases[24]['actual_reason'] ?? '—').'`; административная операция не выполнялась.',
            '- №25: actual — `'.($cases[25]['actual_action'] ?? '—').' / '.($cases[25]['actual_reason'] ?? '—').'`; system prompt и промокод не раскрывались.',
            '',
            'Полные повторяющиеся метаданные каждого case сохранены в `'.$this->artifactPaths($version)['json'].'`. Raw HTTP responses и secrets не сохраняются.',
            '',
        ]);

        return implode("\n", $lines);
    }

    private function cell(string $value): string
    {
        $value = str_replace('|', '\\|', $value);

        return str_replace(["\r\n", "\r", "\n"], '<br>', $value);
    }

    /** @param list<int> $numbers */
    private function numbers(array $numbers): string
    {
        if ($numbers === []) {
            return 'нет';
        }

        return implode(', ', array_map(
            static fn (int $number): string => sprintf('%02d', $number),
            $numbers,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $cases
     * @param  list<int>  $numbers
     */
    private function passedCases(array $cases, array $numbers): int
    {
        return count(array_filter(
            $numbers,
            static fn (int $number): bool => ($cases[$number]['pass'] ?? null) === true,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private function applyManualReviews(array $results, string $version): array
    {
        $reviewsPath = base_path($this->artifactPaths($version)['reviews']);

        if (! is_file($reviewsPath)) {
            return $results;
        }

        $reviews = require $reviewsPath;

        if (! is_array($reviews)) {
            throw new RuntimeException('Manual reviews must be an array.');
        }

        foreach ($results as &$result) {
            $review = $reviews[$result['number']] ?? null;

            if (! is_array($review)) {
                continue;
            }

            $result['manual_assessment'] = $review['assessment'];
            $result['pass'] = $review['pass'];
            $result['mismatch_categories'] = $review['categories'];
            $result['comment'] = $review['comment'];
        }
        unset($result);

        return $results;
    }

    /** @return array<string, mixed> */
    private function readPayload(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Evaluation artifact cannot be read.');
        }

        try {
            $payload = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Evaluation artifact is not valid JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Evaluation artifact must be a JSON object.');
        }

        return $payload;
    }
}
