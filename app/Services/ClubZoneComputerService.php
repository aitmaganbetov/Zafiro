<?php

namespace App\Services;

use App\Models\ClubComputer;
use App\Models\ClubZone;
use Illuminate\Support\Facades\DB;

class ClubZoneComputerService
{
    public function add(ClubZone $zone): ?ClubComputer
    {
        return DB::transaction(function () use ($zone): ?ClubComputer {
            $zone = ClubZone::query()->whereKey($zone->getKey())->lockForUpdate()->firstOrFail();
            $computers = $zone->computers()->lockForUpdate()->get();

            if ($zone->capacity !== null && $computers->count() >= $zone->capacity) {
                return null;
            }

            $number = (int) $computers->max('number') + 1;
            for ($index = 0; ; $index++) {
                $positionX = 5 + (($index % 6) * 47);
                $positionY = 5 + ((int) floor($index / 6) * 42);
                $occupied = $computers->contains(fn (ClubComputer $computer): bool => $computer->position_x === $positionX
                    && $computer->position_y === $positionY);

                if (! $occupied) {
                    break;
                }
            }

            return $zone->computers()->create([
                'number' => $number,
                'device_name' => sprintf('PC-%02d', $number),
                'status' => 'available',
                'position_x' => $positionX,
                'position_y' => $positionY,
            ]);
        });
    }
}
