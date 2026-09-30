<?php

namespace App\Console\Commands;

use App\Telegram\Contracts\TelegramClient;
use App\Telegram\Exceptions\TelegramConfigurationException;
use App\Telegram\Exceptions\TelegramTransportException;
use App\Telegram\TelegramSettings;
use Illuminate\Console\Command;

class TelegramCheckCommand extends Command
{
    protected $signature = 'telegram:check';

    protected $description = 'Safely verify Telegram bot credentials using getMe';

    public function handle(TelegramClient $client): int
    {
        try {
            TelegramSettings::fromConfig();
            $bot = $client->getMe();
        } catch (TelegramConfigurationException|TelegramTransportException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $identity = $bot->username === null ? $bot->displayName : "@{$bot->username}";
        $this->info("Telegram getMe succeeded for {$identity}.");

        return self::SUCCESS;
    }
}
