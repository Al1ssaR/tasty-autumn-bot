<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_participant_id')
                ->constrained('telegram_participants')
                ->restrictOnDelete()
                ->restrictOnUpdate();
            $table->unsignedBigInteger('support_ticket_id')->nullable();
            $table->foreignId('operator_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete()
                ->restrictOnUpdate();
            $table->unsignedBigInteger('bot_decision_id')->nullable()->unique();
            $table->string('author_type', 16);
            $table->text('body');
            $table->bigInteger('telegram_update_id')->nullable()->unique();
            $table->bigInteger('telegram_message_id')->nullable();
            $table->string('delivery_status', 20);
            $table->timestampTz('telegram_sent_at')->nullable();
            $table->string('delivery_error', 500)->nullable();
            $table->integer('delivery_attempt_count')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(
                ['telegram_participant_id', 'created_at', 'id'],
                'messages_participant_history_idx',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE messages
            ADD CONSTRAINT messages_author_type_check
                CHECK (author_type IN ('participant', 'bot', 'system', 'operator')),
            ADD CONSTRAINT messages_body_not_blank_check
                CHECK (btrim(body) <> ''),
            ADD CONSTRAINT messages_delivery_attempt_count_check
                CHECK (delivery_attempt_count >= 0),
            ADD CONSTRAINT messages_author_fields_check
                CHECK (
                    (
                        author_type = 'participant'
                        AND telegram_update_id IS NOT NULL
                        AND telegram_message_id IS NOT NULL
                        AND operator_user_id IS NULL
                        AND bot_decision_id IS NULL
                    )
                    OR (
                        author_type = 'operator'
                        AND operator_user_id IS NOT NULL
                        AND support_ticket_id IS NOT NULL
                        AND telegram_update_id IS NULL
                        AND bot_decision_id IS NULL
                    )
                    OR (
                        author_type IN ('bot', 'system')
                        AND operator_user_id IS NULL
                        AND telegram_update_id IS NULL
                    )
                ),
            ADD CONSTRAINT messages_author_delivery_check
                CHECK (
                    (author_type = 'participant' AND delivery_status = 'not_applicable')
                    OR (
                        author_type IN ('bot', 'system', 'operator')
                        AND delivery_status IN ('pending', 'sent', 'failed')
                    )
                ),
            ADD CONSTRAINT messages_delivery_state_check
                CHECK (
                    (
                        delivery_status = 'not_applicable'
                        AND telegram_sent_at IS NULL
                        AND delivery_error IS NULL
                        AND delivery_attempt_count = 0
                    )
                    OR (
                        delivery_status = 'pending'
                        AND telegram_sent_at IS NULL
                        AND delivery_error IS NULL
                    )
                    OR (
                        delivery_status = 'sent'
                        AND telegram_message_id IS NOT NULL
                        AND telegram_sent_at IS NOT NULL
                        AND delivery_error IS NULL
                        AND delivery_attempt_count >= 1
                    )
                    OR (
                        delivery_status = 'failed'
                        AND telegram_sent_at IS NULL
                        AND delivery_error IS NOT NULL
                        AND btrim(delivery_error) <> ''
                        AND delivery_attempt_count >= 1
                    )
                )
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX messages_ticket_history_idx
                ON messages (support_ticket_id, created_at, id)
                WHERE support_ticket_id IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
