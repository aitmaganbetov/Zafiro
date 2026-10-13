<?php

namespace App\Services;

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ClubComputerRegistrar
{
    public function __construct(private CurrentClub $currentClub, private ActivityLogger $activity) {}

    /** @param array{device_name:string, device_id?:?string, mac_address?:?string, zone_id?:?int} $data
     * @return array{computer:ClubComputer, created:bool}
     */
    public function register(array $data, int $actorId): array
    {
        try {
            return DB::transaction(function () use ($data, $actorId): array {
                $club = Club::query()->with('subscription.plan')
                    ->lockForUpdate()
                    ->findOrFail($this->currentClub->id());
                $deviceId = $data['device_id'] ?? null;

                $existing = $this->findExisting($data);

                if ($existing !== null) {
                    return ['computer' => $existing->load('zone'), 'created' => false];
                }

                $computerLimit = data_get($club->subscription?->plan?->features, 'max_computers');
                if (is_numeric($computerLimit) && ClubComputer::query()->count() >= (int) $computerLimit) {
                    throw ValidationException::withMessages(['device_name' => 'Достигнут лимит компьютеров по тарифу клуба.']);
                }

                $zone = isset($data['zone_id'])
                    ? ClubZone::query()->findOrFail($data['zone_id'])
                    : $this->defaultZone();

                if ($zone->capacity !== null && $zone->computers()->count() >= $zone->capacity) {
                    throw ValidationException::withMessages(['zone_id' => 'В зоне достигнут лимит мест.']);
                }

                $computer = $zone->computers()->create([
                    'number' => ((int) $zone->computers()->max('number')) + 1,
                    'device_name' => $data['device_name'],
                    'device_id' => $deviceId,
                    'mac_address' => $data['mac_address'] ?? null,
                    'status' => 'available',
                ]);
                $this->activity->record('computer.registered', "Агент зарегистрировал ПК {$computer->displayName()} в зоне {$zone->name}", $actorId, $computer, [
                    'device_name' => $computer->device_name,
                    'device_id' => $computer->device_id ?? '—',
                    'mac_address' => $computer->mac_address ?? '—',
                    'zone' => $zone->name,
                ]);

                return ['computer' => $computer->load('zone'), 'created' => true];
            }, 3);
        } catch (QueryException $exception) {
            if (! in_array((int) ($exception->errorInfo[1] ?? 0), [19, 1062], true)) {
                throw $exception;
            }

            $existing = $this->findExisting($data);

            if ($existing !== null) {
                return ['computer' => $existing->load('zone'), 'created' => false];
            }

            throw new ConflictHttpException('Этот device_id уже зарегистрирован.', $exception);
        }
    }

    /** @param array{device_name:string, device_id?:?string} $data */
    private function findExisting(array $data): ?ClubComputer
    {
        $deviceId = $data['device_id'] ?? null;

        return $deviceId !== null && $deviceId !== ''
            ? ClubComputer::query()->where('device_id', $deviceId)->first()
            : ClubComputer::query()
                ->whereRaw('LOWER(device_name) = ?', [mb_strtolower($data['device_name'])])
                ->first();
    }

    private function defaultZone(): ClubZone
    {
        $zone = ClubZone::query()->where('name', 'Не назначена')->where('icon', 'desktop')->first();

        if ($zone !== null) {
            return $zone;
        }

        return ClubZone::query()->create([
            'name' => 'Не назначена',
            'icon' => 'desktop',
            'theme' => 'neutral',
            'status' => 'available',
            'is_featured' => false,
            'sort_order' => 0,
            'position_x' => 20,
            'position_y' => 20,
            'width' => 390,
            'height' => 240,
        ]);
    }
}
