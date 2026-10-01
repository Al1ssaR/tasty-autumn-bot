<?php

namespace App\Console\Commands;

use App\Evaluation\EvaluationReportWriter;
use App\Evaluation\EvaluationRequestLoader;
use App\Evaluation\EvaluationRunner;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

final class BotEvaluateCommand extends Command
{
    protected $signature = 'bot:evaluate
        {--case= : Run one case without changing the baseline report}
        {--no-report : Do not write tracked report artifacts}
        {--render-existing : Rebuild reports from the saved baseline without provider calls}
        {--max-attempts=3 : Maximum attempts for a rate-limited case}';

    protected $description = 'Evaluate bot-v1 against the source requests using the configured LLM';

    public function handle(
        EvaluationRequestLoader $loader,
        EvaluationRunner $runner,
        EvaluationReportWriter $writer,
    ): int {
        if ($this->option('render-existing')) {
            return $this->renderExisting($writer);
        }

        $requests = $loader->load();
        $expected = $this->expected();
        $selected = $this->selectedCases($requests);
        $maxAttempts = filter_var($this->option('max-attempts'), FILTER_VALIDATE_INT);

        if (! is_int($maxAttempts) || $maxAttempts < 1 || $maxAttempts > 5) {
            $this->error('--max-attempts must be between 1 and 5.');

            return self::FAILURE;
        }

        $evaluatedAt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))
            ->format('Y-m-d\TH:i:sP');
        $results = [];

        foreach ($selected as $number => $text) {
            $result = $runner->run(
                $number,
                $text,
                $expected[$number],
                $evaluatedAt,
                $maxAttempts,
            );
            $results[] = $result;
            $actual = $result['actual_action'] === null
                ? $result['technical_failure']
                : $result['actual_action'].'/'.$result['actual_reason'];
            $this->line(sprintf(
                '%02d: %s; exact=%s; attempts=%d',
                $number,
                $actual,
                $result['exact_match'] ? 'yes' : 'no',
                $result['attempts'],
            ));

            if ($number === 22) {
                $this->line('22: provider input redaction='.$result['pii_redaction']);
            }

            if ($this->option('case') !== null && $result['technical_detail'] !== null) {
                $this->line('Safe technical detail: '.$result['technical_detail']);
            }
        }

        $singleCase = $this->option('case') !== null;

        if (! $singleCase && ! $this->option('no-report')) {
            $writer->write($results);
            $this->info('Evaluation reports written to docs/evaluation-results.md and evaluation/results.json.');
        }

        $technicalFailures = count(array_filter(
            $results,
            static fn (array $result): bool => $result['technical_failure'] !== null,
        ));

        return $technicalFailures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<int, array{action: string, reason: string}> */
    private function expected(): array
    {
        $mapping = require base_path('evaluation/expected.php');

        if (! is_array($mapping) || array_keys($mapping) !== range(1, 25)) {
            throw new RuntimeException('Evaluation oracle must contain cases 1 through 25.');
        }

        return $mapping;
    }

    /**
     * @param  array<int, string>  $requests
     * @return array<int, string>
     */
    private function selectedCases(array $requests): array
    {
        $case = $this->option('case');

        if ($case === null) {
            return $requests;
        }

        $number = filter_var($case, FILTER_VALIDATE_INT);

        if (! is_int($number) || ! isset($requests[$number])) {
            throw new RuntimeException('--case must identify one of the 25 source requests.');
        }

        return [$number => $requests[$number]];
    }

    private function renderExisting(EvaluationReportWriter $writer): int
    {
        $contents = file_get_contents(base_path('evaluation/results.json'));

        if ($contents === false) {
            throw new RuntimeException('Saved evaluation results cannot be read.');
        }

        try {
            $payload = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Saved evaluation results are not valid JSON.', previous: $exception);
        }

        $cases = $payload['cases'] ?? null;

        if (! is_array($cases) || count($cases) !== 25) {
            throw new RuntimeException('Saved evaluation results must contain 25 cases.');
        }

        $writer->write($cases);
        $this->info('Evaluation reports rebuilt without provider calls.');

        return self::SUCCESS;
    }
}
