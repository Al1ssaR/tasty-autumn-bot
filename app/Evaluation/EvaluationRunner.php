<?php

namespace App\Evaluation;

use App\Llm\Contracts\LlmDecisionClient;
use App\Llm\Exceptions\LlmApiException;
use App\Llm\Exceptions\LlmRateLimitException;
use App\Llm\Exceptions\LlmTimeoutException;
use App\Llm\Exceptions\MalformedLlmResponseException;
use App\Llm\PiiRedactor;
use App\Llm\PromptAssetLoader;
use App\Llm\PromptContextFactory;
use App\Llm\RuleReferenceIndex;
use App\Llm\StructuredDecisionValidator;
use App\Support\FixedClock;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class EvaluationRunner
{
    public const FROZEN_TIME = '2026-09-30T12:00:00+03:00';

    public function __construct(
        private readonly LlmDecisionClient $client,
        private readonly PromptAssetLoader $assets,
        private readonly PiiRedactor $redactor,
        private readonly StructuredDecisionValidator $validator,
    ) {}

    /**
     * @param  array{action: string, reason: string}  $expected
     * @return array<string, mixed>
     */
    public function run(
        int $number,
        string $originalText,
        array $expected,
        string $evaluatedAt,
        int $maxAttempts = 3,
    ): array {
        $contextFactory = new PromptContextFactory(
            $this->assets,
            $this->redactor,
            new FixedClock(new DateTimeImmutable(
                self::FROZEN_TIME,
                new DateTimeZone('Europe/Moscow'),
            )),
        );
        $request = $contextFactory->build($originalText);
        $identity = $this->client->identity();
        $attempt = 0;
        $piiRedaction = $originalText === $request->userMessage
            ? 'not_applicable'
            : 'applied';

        while (true) {
            $attempt++;

            try {
                $raw = $this->client->decide($request);
            } catch (LlmRateLimitException $exception) {
                if ($attempt >= $maxAttempts) {
                    return $this->technicalFailure(
                        $number,
                        $request->userMessage,
                        $expected,
                        $identity->provider,
                        $identity->model,
                        $request->promptVersion,
                        $request->rulesHash,
                        $evaluatedAt,
                        $attempt,
                        'api_failure',
                        $exception->getMessage(),
                        'not_completed',
                        'not_completed',
                        $piiRedaction,
                    );
                }

                $delay = min(max($exception->retryAfterSeconds ?? 5, 1), 30);
                sleep($delay);

                continue;
            } catch (Throwable $exception) {
                return $this->technicalFailure(
                    $number,
                    $request->userMessage,
                    $expected,
                    $identity->provider,
                    $identity->model,
                    $request->promptVersion,
                    $request->rulesHash,
                    $evaluatedAt,
                    $attempt,
                    $this->safeFailureName($exception),
                    $this->safeFailureDetail($exception),
                    'not_completed',
                    'not_completed',
                    $piiRedaction,
                );
            }

            try {
                $validated = $this->validator->validate(
                    $raw->content,
                    new RuleReferenceIndex($request->rules),
                );
            } catch (Throwable $exception) {
                return $this->technicalFailure(
                    $number,
                    $request->userMessage,
                    $expected,
                    $identity->provider,
                    $identity->model,
                    $request->promptVersion,
                    $request->rulesHash,
                    $evaluatedAt,
                    $attempt,
                    $this->safeFailureName($exception),
                    $this->safeFailureDetail($exception),
                    'passed',
                    'failed',
                    $piiRedaction,
                );
            }

            $exactMatch = $validated->action->value === $expected['action']
                && $validated->reason->value === $expected['reason'];
            $safeAnswer = $this->redactor->redact($validated->answer);

            return [
                'number' => $number,
                'request' => $request->userMessage,
                'expected_action' => $expected['action'],
                'expected_reason' => $expected['reason'],
                'actual_action' => $validated->action->value,
                'actual_reason' => $validated->reason->value,
                'actual_answer' => $safeAnswer,
                'rule_references' => $validated->ruleReferences,
                'schema_validation' => 'passed',
                'semantic_validation' => 'passed',
                'provider' => $identity->provider,
                'model' => $identity->model,
                'prompt_version' => $request->promptVersion,
                'rules_hash' => $request->rulesHash,
                'evaluation_timestamp' => $evaluatedAt,
                'frozen_time' => self::FROZEN_TIME,
                'exact_match' => $exactMatch,
                'technical_failure' => null,
                'technical_detail' => null,
                'attempts' => $attempt,
                'pii_redaction' => $piiRedaction,
                'output_pii_redaction' => $safeAnswer === $validated->answer
                    ? 'not_needed'
                    : 'applied',
                'manual_assessment' => 'pending',
                'pass' => null,
                'comment' => $exactMatch
                    ? 'Ожидает ручной содержательной проверки.'
                    : 'Не совпадают ожидаемые action и/или reason.',
            ];
        }
    }

    /**
     * @param  array{action: string, reason: string}  $expected
     * @return array<string, mixed>
     */
    private function technicalFailure(
        int $number,
        string $safeRequest,
        array $expected,
        ?string $provider,
        ?string $model,
        string $promptVersion,
        string $rulesHash,
        string $evaluatedAt,
        int $attempts,
        string $failure,
        string $safeDetail,
        string $schemaValidation,
        string $semanticValidation,
        string $piiRedaction,
    ): array {
        return [
            'number' => $number,
            'request' => $safeRequest,
            'expected_action' => $expected['action'],
            'expected_reason' => $expected['reason'],
            'actual_action' => null,
            'actual_reason' => null,
            'actual_answer' => '',
            'rule_references' => [],
            'schema_validation' => $schemaValidation,
            'semantic_validation' => $semanticValidation,
            'provider' => $provider,
            'model' => $model,
            'prompt_version' => $promptVersion,
            'rules_hash' => $rulesHash,
            'evaluation_timestamp' => $evaluatedAt,
            'frozen_time' => self::FROZEN_TIME,
            'exact_match' => false,
            'technical_failure' => $failure,
            'technical_detail' => mb_substr($safeDetail, 0, 200),
            'attempts' => $attempts,
            'pii_redaction' => $piiRedaction,
            'output_pii_redaction' => 'not_completed',
            'manual_assessment' => 'technical_failure',
            'pass' => false,
            'comment' => "Технический сбой: {$failure}.",
        ];
    }

    private function safeFailureName(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof LlmTimeoutException => 'timeout',
            $exception instanceof LlmApiException => 'api_failure',
            $exception instanceof MalformedLlmResponseException => 'malformed_response',
            default => 'application_failure',
        };
    }

    private function safeFailureDetail(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof LlmTimeoutException,
            $exception instanceof LlmApiException,
            $exception instanceof MalformedLlmResponseException => $exception->getMessage(),
            default => 'Application evaluation failed.',
        };
    }
}
