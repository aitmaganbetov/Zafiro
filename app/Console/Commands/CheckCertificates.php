<?php

namespace App\Console\Commands;

use App\Services\Monitoring\Certificates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('monitoring:certificates')]
#[Description('Check when the HTTPS certificates of the platform hosts expire')]
class CheckCertificates extends Command
{
    public function handle(Certificates $certificates): int
    {
        foreach ($certificates->refresh() as $host => $expires) {
            $this->line($host.': '.($expires !== null ? date('Y-m-d', $expires) : 'unknown'));
        }

        return self::SUCCESS;
    }
}
