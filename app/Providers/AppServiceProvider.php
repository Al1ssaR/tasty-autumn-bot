<?php

namespace App\Providers;

use App\Llm\Contracts\IncomingMessageClassifier;
use App\Llm\Contracts\LlmDecisionClient;
use App\Llm\Data\LlmClientIdentity;
use App\Llm\GroqLlmDecisionClient;
use App\Llm\MessageClassificationService;
use App\Llm\UnavailableLlmDecisionClient;
use App\Support\Contracts\Clock;
use App\Support\SystemClock;
use App\Telegram\Contracts\IncomingMessageIntake;
use App\Telegram\Contracts\TelegramClient;
use App\Telegram\PostgresIncomingMessageIntake;
use App\Telegram\TelegramHttpClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TelegramClient::class, TelegramHttpClient::class);
        $this->app->bind(IncomingMessageIntake::class, PostgresIncomingMessageIntake::class);
        $this->app->bind(Clock::class, SystemClock::class);
        $this->app->bind(IncomingMessageClassifier::class, MessageClassificationService::class);
        $this->app->bind(LlmDecisionClient::class, function ($app): LlmDecisionClient {
            if ($this->nullableConfigString('llm.provider') === 'groq') {
                return $app->make(GroqLlmDecisionClient::class);
            }

            return new UnavailableLlmDecisionClient(new LlmClientIdentity(
                $this->nullableConfigString('llm.provider'),
                $this->nullableConfigString('llm.model'),
            ));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    private function nullableConfigString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
