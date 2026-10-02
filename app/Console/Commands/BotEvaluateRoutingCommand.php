<?php

namespace App\Console\Commands;

use App\Evaluation\EvaluationRunner;
use App\Evaluation\RoutingEvaluationDataset;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;

final class BotEvaluateRoutingCommand extends Command
{
    protected $signature = 'bot:evaluate-routing
        {--max-attempts=3 : Maximum attempts for a rate-limited case}';

    protected $description = 'Evaluate bot-v3 routing against the separate generalization dataset';

    public function handle(
        RoutingEvaluationDataset $dataset,
        EvaluationRunner $runner,
    ): int {
        $maxAttempts = filter_var($this->option('max-attempts'), FILTER_VALIDATE_INT);

        if (! is_int($maxAttempts) || $maxAttempts < 1 || $maxAttempts > 5) {
            $this->error('--max-attempts must be between 1 and 5.');

            return self::FAILURE;
        }

        $evaluatedAt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))
            ->format('Y-m-d\TH:i:sP');
        $exactMatches = 0;
        $technicalFailures = 0;

        foreach ($dataset->load() as $number => $case) {
            $result = $runner->run(
                $number,
                $case['text'],
                ['action' => $case['action'], 'reason' => $case['reason']],
                $evaluatedAt,
                $maxAttempts,
            );
            $exactMatches += $result['exact_match'] ? 1 : 0;
            $technicalFailures += $result['technical_failure'] === null ? 0 : 1;
            $actual = $result['actual_action'] === null
                ? $result['technical_failure']
                : $result['actual_action'].'/'.$result['actual_reason'];
            $this->line(sprintf(
                '%02d [%s]: %s; expected=%s/%s; exact=%s',
                $number,
                $case['category'],
                $actual,
                $case['action'],
                $case['reason'],
                $result['exact_match'] ? 'yes' : 'no',
            ));
        }

        $this->info(sprintf(
            'Routing evaluation: exact=%d/10; technical_failures=%d.',
            $exactMatches,
            $technicalFailures,
        ));

        return $technicalFailures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
