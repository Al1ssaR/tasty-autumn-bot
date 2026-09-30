<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incoming_message_id')
                ->unique()
                ->constrained('messages')
                ->restrictOnDelete()
                ->restrictOnUpdate();
            $table->string('action', 16);
            $table->string('business_reason', 40)->nullable();
            $table->string('technical_outcome', 40);
            $table->jsonb('rule_references')->default(DB::raw("'[]'::jsonb"));
            $table->string('provider', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('prompt_version', 64);
            $table->string('rules_hash', 64);
            $table->timestampTz('decided_at')->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE bot_decisions
            ADD CONSTRAINT bot_decisions_action_check
                CHECK (action IN ('answer', 'escalate')),
            ADD CONSTRAINT bot_decisions_business_reason_check
                CHECK (
                    business_reason IS NULL
                    OR business_reason IN (
                        'grounded_in_rules',
                        'participant_data_required',
                        'missing_rule',
                        'insufficient_context',
                        'unsafe_request'
                    )
                ),
            ADD CONSTRAINT bot_decisions_technical_outcome_check
                CHECK (
                    technical_outcome IN (
                        'valid',
                        'malformed_response',
                        'timeout',
                        'api_failure',
                        'application_failure'
                    )
                ),
            ADD CONSTRAINT bot_decisions_rule_references_array_check
                CHECK (jsonb_typeof(rule_references) = 'array'),
            ADD CONSTRAINT bot_decisions_rules_hash_check
                CHECK (rules_hash ~ '^[0-9A-Fa-f]{64}$'),
            ADD CONSTRAINT bot_decisions_outcome_consistency_check
                CHECK (
                    (
                        technical_outcome = 'valid'
                        AND business_reason IS NOT NULL
                    )
                    OR (
                        technical_outcome <> 'valid'
                        AND action = 'escalate'
                        AND business_reason IS NULL
                        AND rule_references = '[]'::jsonb
                    )
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_decisions');
    }
};
