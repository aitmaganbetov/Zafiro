<?php

namespace App\Console\Commands;

use App\Services\Telegram;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:poll {--seconds=50 : How long to wait for new messages}')]
#[Description('Take the messages to the Zafiro Telegram bot (linking and unlinking chats) by long polling')]
class PollTelegram extends Command
{
    public function handle(Telegram $telegram): int
    {
        $this->line('handled: '.$telegram->poll(max(0, (int) $this->option('seconds'))));

        return self::SUCCESS;
    }
}
