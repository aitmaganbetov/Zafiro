<?php

namespace App\Console\Commands;

use App\Services\ClubComputerSessions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('club-computers:expire-sessions')]
#[Description('End paid PC sessions whose time is over, including PCs that stopped reporting')]
class ExpireComputerSessions extends Command
{
    public function handle(ClubComputerSessions $sessions): int
    {
        $this->line('expired: '.$sessions->expireAll());

        return self::SUCCESS;
    }
}
