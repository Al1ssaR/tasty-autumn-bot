<?php

namespace App\Llm;

use App\Llm\Data\ValidatedDecision;
use App\Llm\Enums\DecisionAction;
use App\Llm\Enums\DecisionReason;
use App\Llm\Exceptions\MalformedLlmResponseException;
use JsonException;

final class StructuredDecisionValidator
{
    private const FIELDS = ['action', 'answer', 'reason', 'rule_references'];

    private const MAX_RAW_BYTES = 20_000;

    private const MAX_ANSWER_LENGTH = 3_000;

    private const MAX_REFERENCES = 20;

    public function validate(string $rawResponse, RuleReferenceIndex $ruleIndex): ValidatedDecision
    {
        if (strlen($rawResponse) > self::MAX_RAW_BYTES) {
            throw new MalformedLlmResponseException('LLM response exceeds the maximum size.');
        }

        try {
            $payload = json_decode($rawResponse, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MalformedLlmResponseException('LLM response is not valid JSON.', previous: $exception);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw new MalformedLlmResponseException('LLM response must be a JSON object.');
        }

        $keys = array_keys($payload);
        sort($keys);

        if ($keys !== self::FIELDS) {
            throw new MalformedLlmResponseException('LLM response fields do not match the contract.');
        }

        if (! is_string($payload['action'])
            || ! is_string($payload['reason'])
            || ! is_string($payload['answer'])
            || ! is_array($payload['rule_references'])
            || ! array_is_list($payload['rule_references'])) {
            throw new MalformedLlmResponseException('LLM response contains invalid field types.');
        }

        $action = DecisionAction::tryFrom($payload['action']);
        $reason = DecisionReason::tryFrom($payload['reason']);

        if ($action === null || $reason === null) {
            throw new MalformedLlmResponseException('LLM response contains an unknown action or reason.');
        }

        $answer = trim($payload['answer']);

        if (mb_strlen($payload['answer']) > self::MAX_ANSWER_LENGTH) {
            throw new MalformedLlmResponseException('LLM answer exceeds the maximum length.');
        }

        $references = $this->validateReferences($payload['rule_references'], $ruleIndex);

        if ($action === DecisionAction::Answer) {
            if ($reason !== DecisionReason::GroundedInRules || $answer === '' || $references === []) {
                throw new MalformedLlmResponseException('Answer decision violates semantic invariants.');
            }
        } elseif ($reason === DecisionReason::GroundedInRules) {
            throw new MalformedLlmResponseException('Escalation cannot use grounded_in_rules reason.');
        }

        if ($reason === DecisionReason::UnsafeRequest) {
            return new ValidatedDecision($action, $reason, '', []);
        }

        if ($action === DecisionAction::Escalate
            && (($answer === '') !== ($references === []))) {
            throw new MalformedLlmResponseException(
                'Escalation partial answer and rule references are inconsistent.',
            );
        }

        return new ValidatedDecision($action, $reason, $answer, $references);
    }

    /**
     * @param  list<mixed>  $references
     * @return list<string>
     */
    private function validateReferences(array $references, RuleReferenceIndex $ruleIndex): array
    {
        if (count($references) > self::MAX_REFERENCES) {
            throw new MalformedLlmResponseException('Too many rule references.');
        }

        $validated = [];

        foreach ($references as $reference) {
            if (! is_string($reference)
                || strlen($reference) > 16
                || preg_match('/^[1-9][0-9]*\.[1-9][0-9]*$/', $reference) !== 1
                || ! $ruleIndex->contains($reference)
                || in_array($reference, $validated, true)) {
                throw new MalformedLlmResponseException('Rule reference is invalid or unknown.');
            }

            $validated[] = $reference;
        }

        return $validated;
    }
}
