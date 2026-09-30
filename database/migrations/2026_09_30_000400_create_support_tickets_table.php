<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_participant_id')
                ->constrained('telegram_participants')
                ->restrictOnDelete()
                ->restrictOnUpdate();
            $table->foreignId('trigger_message_id')
                ->unique()
                ->constrained('messages')
                ->restrictOnDelete()
                ->restrictOnUpdate();
            $table->string('status', 16)->default('open');
            $table->timestampTz('first_operator_response_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(
                ['telegram_participant_id', 'created_at', 'id'],
                'support_tickets_participant_history_idx',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE support_tickets
            ADD CONSTRAINT support_tickets_status_check
                CHECK (status IN ('open', 'closed')),
            ADD CONSTRAINT support_tickets_first_response_time_check
                CHECK (
                    first_operator_response_at IS NULL
                    OR first_operator_response_at >= created_at
                ),
            ADD CONSTRAINT support_tickets_closed_state_check
                CHECK (
                    (status = 'open' AND closed_at IS NULL)
                    OR (
                        status = 'closed'
                        AND closed_at IS NOT NULL
                        AND closed_at >= created_at
                    )
                )
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX support_tickets_one_open_per_participant_uq
                ON support_tickets (telegram_participant_id)
                WHERE status = 'open'
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX support_tickets_open_queue_idx
                ON support_tickets (created_at, id)
                WHERE status = 'open'
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
