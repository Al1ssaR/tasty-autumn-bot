<?php

namespace App\Console\Commands;

use App\Evaluation\EvaluationRequestLoader;
use App\Evaluation\EvaluationTelegramClient;
use App\Llm\Contracts\LlmDecisionClient;
use App\Llm\MessageClassificationService;
use App\Llm\PromptContextFactory;
use App\Llm\StructuredDecisionValidator;
use App\Models\Message;
use App\Models\TelegramParticipant;
use App\Support\Contracts\Clock;
use App\Telegram\TelegramMessageDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BotLlmApplicationSmokeCommand extends Command
{
    protected $signature = 'bot:llm-application-smoke';

    protected $description = 'Run real LLM classification with rollback and a fake Telegram transport';

    public function handle(
        EvaluationRequestLoader $loader,
        LlmDecisionClient $client,
        PromptContextFactory $contextFactory,
        StructuredDecisionValidator $validator,
        Clock $clock,
    ): int {
        $database = DB::connection()->getDatabaseName();

        if (! str_ends_with($database, '_test')) {
            $this->error('Application smoke requires an isolated database whose name ends with _test.');

            return self::FAILURE;
        }

        $telegram = new EvaluationTelegramClient;
        $service = new MessageClassificationService(
            $client,
            $contextFactory,
            $validator,
            new TelegramMessageDelivery($telegram, $clock),
            $clock,
        );
        DB::beginTransaction();

        try {
            $participant = TelegramParticipant::query()->create([
                'telegram_chat_id' => 9_000_000_001,
            ]);
            $incoming = Message::query()->create([
                'telegram_participant_id' => $participant->id,
                'support_ticket_id' => null,
                'operator_user_id' => null,
                'bot_decision_id' => null,
                'author_type' => 'participant',
                'body' => $loader->load()[1],
                'telegram_update_id' => 9_000_000_001,
                'telegram_message_id' => 9_000_000_001,
                'delivery_status' => 'not_applicable',
                'telegram_sent_at' => null,
                'delivery_error' => null,
                'delivery_attempt_count' => 0,
            ]);
            $result = $service->classify($incoming);

            if ($result->decision->technical_outcome !== 'valid'
                || $result->decision->provider !== 'groq'
                || $result->decision->model !== 'openai/gpt-oss-120b'
                || $result->decision->prompt_version !== 'bot-v1'
                || strlen($result->decision->rules_hash) !== 64
                || $result->outgoingMessage->delivery_status !== 'sent'
                || $telegram->sentMessages !== 1) {
                $this->error('Application smoke invariants failed.');

                return self::FAILURE;
            }

            $this->info(sprintf(
                'Application smoke passed: action=%s, reason=%s, provider=%s, model=%s, fake_telegram=sent.',
                $result->decision->action,
                $result->decision->business_reason,
                $result->decision->provider,
                $result->decision->model,
            ));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Application smoke failed safely.');

            return self::FAILURE;
        } finally {
            DB::rollBack();
        }
    }
}
