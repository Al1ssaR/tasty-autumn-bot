<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreign('support_ticket_id')
                ->references('id')
                ->on('support_tickets')
                ->restrictOnDelete()
                ->restrictOnUpdate();
            $table->foreign('bot_decision_id')
                ->references('id')
                ->on('bot_decisions')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['support_ticket_id']);
            $table->dropForeign(['bot_decision_id']);
        });
    }
};
