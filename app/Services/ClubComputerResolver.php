<?php

namespace App\Services;

use App\Models\ClubComputer;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ClubComputerResolver
{
    public function __construct(private CurrentClub $currentClub) {}

    public function resolve(string $deviceName, ?string $deviceId): ?ClubComputer
    {
        $query = ClubComputer::query()->with('zone');

        if ($deviceId !== null && $deviceId !== '') {
            $computer = (clone $query)->where('device_id', $deviceId)->first();

            if ($computer !== null) {
                return $computer;
            }

        }

        $matches = (clone $query)
            ->whereRaw('LOWER(device_name) = ?', [mb_strtolower($deviceName)])
            ->limit(2)
            ->get();

        if ($matches->isEmpty()) {
            return null;
        }

        if ($matches->count() > 1) {
            throw new ConflictHttpException('Имя компьютера не уникально в клубе; задайте device_id.');
        }

        return $matches->first();
    }
}
