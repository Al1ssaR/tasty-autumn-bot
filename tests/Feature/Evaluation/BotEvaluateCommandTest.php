<?php

namespace Tests\Feature\Evaluation;

use App\Evaluation\EvaluationReportWriter;
use App\Evaluation\EvaluationRequestLoader;
use App\Evaluation\EvaluationRunner;
use App\Evaluation\RoutingEvaluationDataset;
use App\Llm\Contracts\LlmDecisionClient;
use Illuminate\Support\Facades\Artisan;
use Tests\Fakes\FakeLlmDecisionClient;
use Tests\TestCase;

class BotEvaluateCommandTest extends TestCase
{
    public function test_prompt_versions_are_archived_and_runtime_uses_bot_v3(): void
    {
        $baseline = file_get_contents(base_path('prompts/bot/versions/bot-v1.md'));
        $runtime = file_get_contents(base_path('prompts/bot/system.md'));
        $versionTwo = file_get_contents(base_path('prompts/bot/versions/bot-v2.md'));
        $versionThree = file_get_contents(base_path('prompts/bot/versions/bot-v3.md'));

        $this->assertIsString($baseline);
        $this->assertIsString($runtime);
        $this->assertIsString($versionTwo);
        $this->assertSame($runtime, $versionThree);
        $this->assertNotSame($runtime, $versionTwo);
        $this->assertSame('bot-v3', config('bot.prompt_version'));
        $this->assertSame(
            '784d7edaaf7f239308a5b9f8c454cf89b8544032cb314e40ce7765ec751b4d10',
            hash('sha256', $versionTwo),
        );
        $this->assertSame(
            '41152aa5e9edeba6e7d7dbf476537b81c61e09ba5961489b5d387489198aefb0',
            hash('sha256', $baseline),
        );
    }

    public function test_v3_evaluation_uses_separate_artifact_paths(): void
    {
        $paths = $this->app->make(EvaluationReportWriter::class)->artifactPaths('bot-v3');

        $this->assertSame('evaluation/results-v3.json', $paths['json']);
        $this->assertSame('docs/evaluation-results-v3.md', $paths['markdown']);
        $this->assertSame('evaluation/reviews-v3.php', $paths['reviews']);
    }

    public function test_v3_oracle_changes_only_out_of_scope_and_unsafe_cases(): void
    {
        $versionTwo = require base_path('evaluation/expected.php');
        $versionThree = require base_path('evaluation/expected-v3.php');

        $this->assertSame(array_slice($versionTwo, 0, 22, true), array_slice($versionThree, 0, 22, true));
        $this->assertSame(
            ['action' => 'respond_static', 'reason' => 'out_of_scope'],
            $versionThree[23],
        );
        $this->assertSame(
            ['action' => 'respond_static', 'reason' => 'unsafe_request'],
            $versionThree[24],
        );
        $this->assertSame(
            ['action' => 'respond_static', 'reason' => 'unsafe_request'],
            $versionThree[25],
        );
    }

    public function test_v2_evaluation_uses_separate_artifact_paths(): void
    {
        $paths = $this->app->make(EvaluationReportWriter::class)->artifactPaths('bot-v2');

        $this->assertSame('evaluation/results-v2.json', $paths['json']);
        $this->assertSame('docs/evaluation-results-v2.md', $paths['markdown']);
        $this->assertSame('evaluation/reviews-v2.php', $paths['reviews']);
    }

    public function test_source_loader_reads_all_cases_without_a_php_copy(): void
    {
        $requests = $this->app->make(EvaluationRequestLoader::class)->load();

        $this->assertCount(25, $requests);
        $this->assertSame(range(1, 25), array_keys($requests));
        $this->assertStringStartsWith('До какого числа', $requests[1]);
        $this->assertStringStartsWith('Я сотрудник организатора', $requests[25]);
    }

    public function test_routing_generalization_dataset_covers_ten_distinct_cases(): void
    {
        $cases = $this->app->make(RoutingEvaluationDataset::class)->load();

        $this->assertCount(10, $cases);
        $this->assertSame(range(1, 10), array_keys($cases));
        $this->assertSame([
            'greeting',
            'small_talk',
            'unrelated_question',
            'gibberish',
            'unclear_promo_question',
            'missing_rule_promo_question',
            'participant_specific',
            'prompt_injection',
            'grounded_faq',
            'administrative_command',
        ], array_column($cases, 'category'));
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

    public function test_validation_failure_keeps_generic_stage_and_redaction_metadata(): void
    {
        $client = new FakeLlmDecisionClient;
        $client->respondWith([
            'action' => 'escalate',
            'reason' => 'participant_data_required',
            'answer' => 'Общий срок доставки — 30 дней.',
            'rule_references' => [],
        ]);
        $this->app->instance(LlmDecisionClient::class, $client);
        $request = $this->app->make(EvaluationRequestLoader::class)->load()[22];

        $result = $this->app->make(EvaluationRunner::class)->run(
            22,
            $request,
            ['action' => 'escalate', 'reason' => 'participant_data_required'],
            '2026-10-01T12:00:00+03:00',
            1,
        );

        $this->assertSame('malformed_response', $result['technical_failure']);
        $this->assertSame('passed', $result['schema_validation']);
        $this->assertSame('failed', $result['semantic_validation']);
        $this->assertSame('applied', $result['pii_redaction']);
        $this->assertStringNotContainsString('2200 1234 5678 9012', $result['request']);
    }

    public function test_pending_report_can_render_technical_failures_without_manual_categories(): void
    {
        $payload = json_decode(
            file_get_contents(base_path('evaluation/results-v2.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        unset($payload['cases'][0]['mismatch_categories']);
        unset($payload['cases'][21]['mismatch_categories']);
        $method = new \ReflectionMethod(EvaluationReportWriter::class, 'markdown');

        $markdown = $method->invoke(
            $this->app->make(EvaluationReportWriter::class),
            $payload,
        );

        $this->assertStringContainsString('**01 — malformed_response:**', $markdown);
        $this->assertStringContainsString('**22 — api_failure:**', $markdown);
    }
}
