<?php

namespace App\Console\Commands;

use Illuminate\Support\Str;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Telegram;
use PhpTelegramBot\Laravel\Console\Commands\TelegramFetchCommand;

class TelegramCustomFetchCommand extends TelegramFetchCommand
{
    protected $signature = 'telegram:custom-fetch
                            {--a|all-update-types : Explicitly allow all updates (including "chat_member")}
                            {--timeout= : Max execution time in seconds (default: 60)}
                            {--allowed-updates= : Define allowed updates (comma-seperated)}';

    public function handle(Telegram $bot)
    {
        $startTime = time();
        $timeout = $this->option('timeout') ?: 60;

        $this->callSilent('telegram:delete-webhook');

        $options = [
            'timeout' => 30
        ];

        // allowed_updates
        if ($this->option('all-update-types')) {
            $options['allowed_updates'] = Update::getUpdateTypes();
        } elseif ($allowedUpdates = $this->option('allowed-updates')) {
            $options['allowed_updates'] = Str::of($allowedUpdates)->explode(',');
        }

        $this->info("Start fetching updates...\n<comment>(Exit with Ctrl + C)</comment>");

        if ($this->childPid = pcntl_fork()) {
            // Parent process

            while (time() - $startTime < $timeout) {

                if ($this->shallExit) {
                    exec('kill -9 ' . $this->childPid);
                    break;
                }

                usleep(500);
            }

        } else {
            // Child process

            while (time() - $startTime < $timeout) {

                $response = rescue(fn() => $bot->handleGetUpdates($options));

                if ($response !== null && ! $response->isOk()) {
                    $this->error($response->getDescription());
                }

                usleep(500);
            }

        }
    }
}
