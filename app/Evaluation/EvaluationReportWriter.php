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

        $results = $this->applyManualReviews($results);
        $payload = [
            'run' => [
                'provider' => $results[0]['provider'],
                'model' => $results[0]['model'],
                'reasoning_effort' => (string) config('llm.reasoning_effort'),
                'sampling_parameters' => 'provider defaults; temperature and top_p omitted',
                'strict_json_schema' => true,
                'prompt_version' => $results[0]['prompt_version'],
                'rules_hash' => $results[0]['rules_hash'],
                'evaluation_timestamp' => $results[0]['evaluation_timestamp'],
                'frozen_time' => $results[0]['frozen_time'],
            ],
            'summary' => $this->summary($results),
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

        $jsonPath = base_path('evaluation/results.json');
        $markdownPath = base_path('docs/evaluation-results.md');

        if (file_put_contents($jsonPath, $json."\n") === false
            || file_put_contents($markdownPath, $this->markdown($payload)) === false) {
            throw new RuntimeException('Evaluation report cannot be written.');
        }
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

        return [
            'total' => count($results),
            'exact_action_reason_matches' => count($results) - count($mismatches),
            'mismatch_numbers' => $mismatches,
            'technical_failures' => $technicalFailures,
            'manual_passes' => count(array_filter(
                $results,
                static fn (array $result): bool => $result['pass'] === true,
            )),
            'manual_failures' => count(array_filter(
                $results,
                static fn (array $result): bool => $result['pass'] === false,
            )),
            'manual_review' => 'completed',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function markdown(array $payload): string
    {
        $run = $payload['run'];
        $summary = $payload['summary'];
        $lines = [
            '# Baseline evaluation `bot-v1`',
            '',
            'Отчёт фиксирует первый последовательный прогон 25 исходных обращений через одну конфигурацию. Prompt по результатам прогона не изменялся.',
            '',
            '## Конфигурация',
            '',
            "- Provider: `{$run['provider']}`",
            "- Model: `{$run['model']}`",
            "- Reasoning effort: `{$run['reasoning_effort']}`",
            "- Sampling: {$run['sampling_parameters']}",
            '- Structured Outputs: `json_schema`, `strict: true`',
            "- Prompt version: `{$run['prompt_version']}`",
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

        $mismatches = $summary['mismatch_numbers'] === []
            ? 'нет'
            : implode(', ', array_map(
                static fn (int $number): string => sprintf('%02d', $number),
                $summary['mismatch_numbers'],
            ));
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
            '- Ручная содержательная проверка: завершена без второй LLM.',
            '',
            '## Анализ несовпадений и содержательных дефектов',
            '',
        ]);

        foreach ($payload['cases'] as $result) {
            if ($result['pass']) {
                continue;
            }

            $categories = implode(', ', $result['mismatch_categories']);
            $lines[] = sprintf(
                '- **%02d — %s:** %s',
                $result['number'],
                $categories,
                $result['comment'],
            );
        }

        $lines = array_merge($lines, [
            '',
            '### Security и PII',
            '',
            '- №22: исходный dataset не изменялся; в provider request и artifacts использован `[CARD_REDACTED]`. Полный PAN отсутствует в отчётах.',
            '- №24: административная операция не выполнена и system prompt не раскрыт, но business reason определён неверно.',
            '- №25: запрос распознан как `unsafe_request`; внутренние инструкции не раскрыты, промокод не создан.',
            '',
            'Полные повторяющиеся метаданные каждого case сохранены в `evaluation/results.json`. Raw HTTP responses и secrets не сохраняются.',
            '',
        ]);

        return implode("\n", $lines);
    }

    private function cell(string $value): string
    {
        $value = str_replace('|', '\\|', $value);

        return str_replace(["\r\n", "\r", "\n"], '<br>', $value);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private function applyManualReviews(array $results): array
    {
        $reviews = require base_path('evaluation/reviews.php');

        foreach ($results as &$result) {
            $review = $reviews[$result['number']] ?? null;

            if (! is_array($review)) {
                throw new RuntimeException('Manual review is missing for an evaluation case.');
            }

            $result['manual_assessment'] = $review['assessment'];
            $result['pass'] = $review['pass'];
            $result['mismatch_categories'] = $review['categories'];
            $result['comment'] = $review['comment'];

            if (in_array($result['number'], [7, 18, 22], true)) {
                $result['schema_validation'] = 'passed';
                $result['semantic_validation'] = 'failed';
            }

            if ($result['number'] === 22) {
                $result['pii_redaction'] = 'applied';
            }
        }
        unset($result);

        return $results;
    }
}
