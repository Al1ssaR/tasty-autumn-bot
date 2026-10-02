<?php

namespace Tests\Unit\Llm;

use App\Llm\Enums\DecisionAction;
use App\Llm\Enums\DecisionReason;
use App\Llm\Exceptions\MalformedLlmResponseException;
use App\Llm\RuleReferenceIndex;
use App\Llm\StructuredDecisionValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StructuredDecisionValidatorTest extends TestCase
{
    private StructuredDecisionValidator $validator;

    private RuleReferenceIndex $rules;

    protected function setUp(): void
    {
        $this->validator = new StructuredDecisionValidator;
        $this->rules = new RuleReferenceIndex("1.1. Первое правило.\n2.3. Второе правило.\n");
    }

    public function test_valid_answer_is_parsed(): void
    {
        $decision = $this->validator->validate($this->json([
            'action' => 'answer',
            'reason' => 'grounded_in_rules',
            'answer' => 'Ответ по правилам.',
            'rule_references' => ['1.1', '2.3'],
        ]), $this->rules);

        $this->assertSame(DecisionAction::Answer, $decision->action);
        $this->assertSame(DecisionReason::GroundedInRules, $decision->reason);
        $this->assertSame(['1.1', '2.3'], $decision->ruleReferences);
    }

    public function test_valid_partial_escalation_is_parsed(): void
    {
        $decision = $this->validator->validate($this->json([
            'action' => 'escalate',
            'reason' => 'participant_data_required',
            'answer' => 'Общая справка.',
            'rule_references' => ['2.3'],
        ]), $this->rules);

        $this->assertSame(DecisionAction::Escalate, $decision->action);
        $this->assertSame('Общая справка.', $decision->answer);
    }

    #[DataProvider('staticResponses')]
    public function test_static_generated_content_is_discarded(string $reason): void
    {
        $decision = $this->validator->validate($this->json([
            'action' => 'respond_static',
            'reason' => $reason,
            'answer' => 'Недоверенный сгенерированный текст.',
            'rule_references' => [],
        ]), $this->rules);

        $this->assertSame(DecisionAction::RespondStatic, $decision->action);
        $this->assertSame('', $decision->answer);
        $this->assertSame([], $decision->ruleReferences);
    }

    /** @return iterable<string, array{string}> */
    public static function staticResponses(): iterable
    {
        yield 'insufficient context' => ['insufficient_context'];
        yield 'out of scope' => ['out_of_scope'];
        yield 'unsafe request' => ['unsafe_request'];
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_results_are_rejected(string $raw): void
    {
        $this->expectException(MalformedLlmResponseException::class);

        $this->validator->validate($raw, $this->rules);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidResponses(): iterable
    {
        yield 'invalid JSON' => ['not-json'];
        yield 'unknown action' => [self::encode([
            'action' => 'ignore',
            'reason' => 'missing_rule',
            'answer' => '',
            'rule_references' => [],
        ])];
        yield 'unknown reason' => [self::encode([
            'action' => 'escalate',
            'reason' => 'other',
            'answer' => '',
            'rule_references' => [],
        ])];
        yield 'answer without references' => [self::encode([
            'action' => 'answer',
            'reason' => 'grounded_in_rules',
            'answer' => 'Ответ.',
            'rule_references' => [],
        ])];
        yield 'unknown reference' => [self::encode([
            'action' => 'answer',
            'reason' => 'grounded_in_rules',
            'answer' => 'Ответ.',
            'rule_references' => ['9.9'],
        ])];
        yield 'answer with participant reason' => [self::encode([
            'action' => 'answer',
            'reason' => 'participant_data_required',
            'answer' => 'Ответ.',
            'rule_references' => ['1.1'],
        ])];
        yield 'escalate with grounded reason' => [self::encode([
            'action' => 'escalate',
            'reason' => 'grounded_in_rules',
            'answer' => '',
            'rule_references' => [],
        ])];
        yield 'escalate with unsafe reason' => [self::encode([
            'action' => 'escalate',
            'reason' => 'unsafe_request',
            'answer' => '',
            'rule_references' => [],
        ])];
        yield 'static with missing rule reason' => [self::encode([
            'action' => 'respond_static',
            'reason' => 'missing_rule',
            'answer' => '',
            'rule_references' => [],
        ])];
        yield 'static with references' => [self::encode([
            'action' => 'respond_static',
            'reason' => 'out_of_scope',
            'answer' => '',
            'rule_references' => ['1.1'],
        ])];
        yield 'unexpected field' => [self::encode([
            'action' => 'escalate',
            'reason' => 'missing_rule',
            'answer' => '',
            'rule_references' => [],
            'debug' => true,
        ])];
        yield 'wrong field type' => [self::encode([
            'action' => 'answer',
            'reason' => 'grounded_in_rules',
            'answer' => ['not', 'a', 'string'],
            'rule_references' => ['1.1'],
        ])];
        yield 'partial answer without references' => [self::encode([
            'action' => 'escalate',
            'reason' => 'missing_rule',
            'answer' => 'Частичный ответ.',
            'rule_references' => [],
        ])];
        yield 'references without partial answer' => [self::encode([
            'action' => 'escalate',
            'reason' => 'missing_rule',
            'answer' => '',
            'rule_references' => ['1.1'],
        ])];
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        return self::encode($payload);
    }

    /** @param array<string, mixed> $payload */
    private static function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
