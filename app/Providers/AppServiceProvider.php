<?php

namespace App\Providers;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
