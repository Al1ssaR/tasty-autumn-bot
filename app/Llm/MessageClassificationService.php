<?php

namespace App\Llm;

use App\Llm\Contracts\IncomingMessageClassifier;
use App\Llm\Contracts\LlmDecisionClient;
use App\Llm\Data\ClassificationResult;
use App\Llm\Data\ValidatedDecision;
use App\Llm\Enums\DecisionAction;
use App\Llm\Enums\DecisionReason;
use App\Llm\Enums\TechnicalOutcome;
use App\Llm\Exceptions\LlmApiException;
use App\Llm\Exceptions\LlmTimeoutException;
use App\Llm\Exceptions\MalformedLlmResponseException;
use App\Models\BotDecision;
use App\Models\Message;
use App\Models\SupportTicket;
use App\Models\TelegramParticipant;
use App\Support\Contracts\Clock;
use App\Telegram\TelegramMessageDelivery;
use Illuminate\Support\Facades\DB;
use Throwable;

final class MessageClassificationService implements IncomingMessageClassifier
{
    public function __construct(
        private readonly LlmDecisionClient $client,
        private readonly PromptContextFactory $contextFactory,
        private readonly StructuredDecisionValidator $validator,
        private readonly TelegramMessageDelivery $delivery,
        private readonly Clock $clock,
    ) {}

    public function classify(Message $incomingMessage): ClassificationResult
    {
        $incomingMessage->refresh();

        $existing = $this->existingResult($incomingMessage);

        if ($existing !== null) {
            return $existing;
        }

        $this->assertIncomingMessage($incomingMessage);

        $identity = $this->client->identity();
        $request = null;
        $decision = null;
        $outcome = TechnicalOutcome::ApplicationFailure;

        try {
            $request = $this->contextFactory->build($incomingMessage->body);
            $rawResponse = $this->client->decide($request);
            $decision = $this->validator->validate(
                $rawResponse->content,
                new RuleReferenceIndex($request->rules),
            );
            $outcome = TechnicalOutcome::Valid;
        } catch (LlmTimeoutException) {
            $outcome = TechnicalOutcome::Timeout;
        } catch (LlmApiException) {
            $outcome = TechnicalOutcome::ApiFailure;
        } catch (MalformedLlmResponseException) {
            $outcome = TechnicalOutcome::MalformedResponse;
        } catch (Throwable) {
            $outcome = TechnicalOutcome::ApplicationFailure;
        }

        $promptVersion = $request?->promptVersion
            ?? (string) config('bot.prompt_version', 'bot-v1');
        $rulesHash = $request?->rulesHash ?? hash('sha256', '');
        $persisted = $this->persist(
            $incomingMessage,
            $decision,
            $outcome,
            $identity->provider,
            $identity->model,
            $promptVersion,
            $rulesHash,
        );

        if ($persisted->created) {
            $this->delivery->deliver($persisted->outgoingMessage);
        }

        return new ClassificationResult(
            $persisted->decision->refresh(),
            $persisted->outgoingMessage->refresh(),
            $persisted->ticket?->refresh(),
            $persisted->created,
        );
    }

    private function assertIncomingMessage(Message $message): void
    {
        if ($message->author_type !== 'participant'
            || $message->delivery_status !== 'not_applicable'
            || $message->support_ticket_id !== null) {
            throw new \InvalidArgumentException(
                'Only a new participant message outside an open ticket can be classified.',
            );
        }
    }

    private function existingResult(Message $incomingMessage): ?ClassificationResult
    {
        $decision = BotDecision::query()
            ->where('incoming_message_id', $incomingMessage->id)
            ->first();

        if ($decision === null) {
            return null;
        }

        $outgoing = Message::query()
            ->where('bot_decision_id', $decision->id)
            ->firstOrFail();
        $ticket = $outgoing->support_ticket_id === null
            ? SupportTicket::query()
                ->where('trigger_message_id', $incomingMessage->id)
                ->first()
            : SupportTicket::query()->find($outgoing->support_ticket_id);

        return new ClassificationResult($decision, $outgoing, $ticket, false);
    }

    private function persist(
        Message $incomingMessage,
        ?ValidatedDecision $validated,
        TechnicalOutcome $outcome,
        ?string $provider,
        ?string $model,
        string $promptVersion,
        string $rulesHash,
    ): ClassificationResult {
        return DB::transaction(function () use (
            $incomingMessage,
            $validated,
            $outcome,
            $provider,
            $model,
            $promptVersion,
            $rulesHash,
        ): ClassificationResult {
            $lockedMessage = Message::query()->lockForUpdate()->findOrFail($incomingMessage->id);
            $existing = $this->existingResult($lockedMessage);

            if ($existing !== null) {
                return $existing;
            }

            $this->assertIncomingMessage($lockedMessage);

            $action = $outcome === TechnicalOutcome::Valid
                ? $validated?->action
                : DecisionAction::Escalate;

            if ($action === null) {
                throw new \LogicException('A valid classification must contain a decision.');
            }

            $decision = BotDecision::query()->create([
                'incoming_message_id' => $lockedMessage->id,
                'action' => $action->value,
                'business_reason' => $outcome === TechnicalOutcome::Valid
                    ? $validated?->reason->value
                    : null,
                'technical_outcome' => $outcome->value,
                'rule_references' => $outcome === TechnicalOutcome::Valid
                    ? $validated?->ruleReferences ?? []
                    : [],
                'provider' => $this->boundedNullable($provider, 80),
                'model' => $this->boundedNullable($model, 120),
                'prompt_version' => mb_substr($promptVersion, 0, 64),
                'rules_hash' => $rulesHash,
                'decided_at' => $this->clock->now(),
            ]);

            $ticket = null;

            if ($action === DecisionAction::Escalate) {
                TelegramParticipant::query()
                    ->lockForUpdate()
                    ->findOrFail($lockedMessage->telegram_participant_id);
                $ticket = SupportTicket::query()
                    ->where('telegram_participant_id', $lockedMessage->telegram_participant_id)
                    ->where('status', 'open')
                    ->first();

                if ($ticket === null) {
                    $ticket = SupportTicket::query()->create([
                        'telegram_participant_id' => $lockedMessage->telegram_participant_id,
                        'trigger_message_id' => $lockedMessage->id,
                        'status' => 'open',
                    ]);
                } else {
                    $lockedMessage->update(['support_ticket_id' => $ticket->id]);
                }
            }

            $outgoing = Message::query()->create([
                'telegram_participant_id' => $lockedMessage->telegram_participant_id,
                'support_ticket_id' => $ticket?->id,
                'operator_user_id' => null,
                'bot_decision_id' => $decision->id,
                'author_type' => $action === DecisionAction::Answer ? 'bot' : 'system',
                'body' => $this->outgoingBody($validated, $outcome),
                'telegram_update_id' => null,
                'telegram_message_id' => null,
                'delivery_status' => 'pending',
                'telegram_sent_at' => null,
                'delivery_error' => null,
                'delivery_attempt_count' => 0,
            ]);

            return new ClassificationResult($decision, $outgoing, $ticket, true);
        });
    }

    private function outgoingBody(
        ?ValidatedDecision $decision,
        TechnicalOutcome $outcome,
    ): string {
        if ($outcome === TechnicalOutcome::Valid
            && $decision?->action === DecisionAction::Answer) {
            return $decision->answer;
        }

        $notice = trim((string) config('bot.escalation_notice'));

        if ($outcome === TechnicalOutcome::Valid
            && $decision?->reason === DecisionReason::UnsafeRequest) {
            return trim((string) config('bot.unsafe_response'))."\n\n{$notice}";
        }

        if ($outcome === TechnicalOutcome::Valid && $decision?->answer !== '') {
            return $decision->answer."\n\n{$notice}";
        }

        return $notice;
    }

    private function boundedNullable(?string $value, int $limit): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
