<?php

namespace App\Services;

use App\Models\ClubComputer;
use App\Support\MacAddress;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ClubComputerResolver
{
    public function __construct(private CurrentClub $currentClub, private ClubComputerRegistrar $registrar) {}

    public function resolve(string $deviceName, ?string $deviceId, ?string $macAddress = null): ?ClubComputer
    {
        // With a MAC the PC is found only by it: the name and the UUID come from the boot image.
        $mac = MacAddress::normalize($macAddress);
        if ($mac !== null) {
            $computer = $this->registrar->find(['device_name' => $deviceName, 'device_id' => $deviceId, 'mac_address' => $mac]);

            return $computer === null ? null : $this->registrar->adoptMac($computer, $mac)->load('zone');
        }

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
            throw new ConflictHttpException(__('Имя компьютера не уникально в клубе; задайте device_id.'));
        }

        return $matches->first();
    }
}
