<?php

namespace Tests\Feature\Evaluation;

use App\Evaluation\EvaluationRequestLoader;
use App\Llm\Contracts\LlmDecisionClient;
use Illuminate\Support\Facades\Artisan;
use Tests\Fakes\FakeLlmDecisionClient;
use Tests\TestCase;

class BotEvaluateCommandTest extends TestCase
{
    public function test_source_loader_reads_all_cases_without_a_php_copy(): void
    {
        $requests = $this->app->make(EvaluationRequestLoader::class)->load();

        $this->assertCount(25, $requests);
        $this->assertSame(range(1, 25), array_keys($requests));
        $this->assertStringStartsWith('До какого числа', $requests[1]);
        $this->assertStringStartsWith('Я сотрудник организатора', $requests[25]);
    }

    public function test_case_22_uses_frozen_time_and_sends_only_redacted_text_to_client(): void
    {
        $client = new FakeLlmDecisionClient;
        $client->respondWith([
            'action' => 'escalate',
            'reason' => 'participant_data_required',
            'answer' => '',
            'rule_references' => [],
        ]);
        $this->app->instance(LlmDecisionClient::class, $client);

        $exitCode = Artisan::call('bot:evaluate', [
            '--case' => 22,
            '--no-report' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertCount(1, $client->requests);
        $this->assertSame(
            '2026-09-30T12:00:00+03:00 [Europe/Moscow]',
            $client->requests[0]->currentTime,
        );
        $this->assertStringContainsString('[CARD_REDACTED]', $client->requests[0]->userMessage);
        $this->assertStringNotContainsString(
            '2200 1234 5678 9012',
            $client->requests[0]->userMessage,
        );
        $this->assertStringNotContainsString('2200 1234 5678 9012', Artisan::output());
    }
}
