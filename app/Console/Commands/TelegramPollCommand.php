<?php

namespace App\Console\Commands;

use App\Telegram\Exceptions\TelegramConfigurationException;
use App\Telegram\Exceptions\TelegramTransportException;
use App\Telegram\TelegramPollingWorker;
use App\Telegram\TelegramSettings;
use Illuminate\Console\Command;
use Throwable;

class TelegramPollCommand extends Command
{
    protected $signature = 'telegram:poll
                            {--once : Execute one long-polling batch and exit}';

    protected $description = 'Receive Telegram updates using long polling';

    public function handle(TelegramPollingWorker $worker): int
    {
        try {
            $settings = TelegramSettings::fromConfig();
        } catch (TelegramConfigurationException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $once = (bool) $this->option('once');
        $offset = null;

        do {
            try {
                $batch = $worker->pollOnce($offset);
                $offset = $batch->nextOffset;

                if ($once) {
                    $nextOffset = $offset === null ? 'none' : (string) $offset;
                    $this->info(
                        "Telegram polling batch completed: {$batch->receivedUpdates} update(s), "
                        ."next offset {$nextOffset}.",
                    );
                }
            } catch (TelegramTransportException $exception) {
                $this->error($exception->getMessage());

                if ($once) {
                    return self::FAILURE;
                }

                sleep($settings->pollBackoffSeconds);
            } catch (Throwable) {
                $this->error('Telegram update processing failed; the batch will be retried.');

                if ($once) {
                    return self::FAILURE;
                }

                sleep($settings->pollBackoffSeconds);
            }
        } while (! $once);

        return self::SUCCESS;
    }
}
