<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE bot_decisions
            DROP CONSTRAINT bot_decisions_action_check,
            DROP CONSTRAINT bot_decisions_business_reason_check,
            ADD CONSTRAINT bot_decisions_action_check
                CHECK (action IN ('answer', 'escalate', 'respond_static')),
            ADD CONSTRAINT bot_decisions_business_reason_check
                CHECK (
                    business_reason IS NULL
                    OR business_reason IN (
                        'grounded_in_rules',
                        'participant_data_required',
                        'missing_rule',
                        'insufficient_context',
                        'unsafe_request',
                        'out_of_scope'
                    )
                )
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE bot_decisions
            DROP CONSTRAINT bot_decisions_action_check,
            DROP CONSTRAINT bot_decisions_business_reason_check,
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
                )
            SQL);
    }
};
