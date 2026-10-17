<?php

namespace App\Services;

use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Support\MacAddress;
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
                $mac = MacAddress::normalize($data['mac_address'] ?? null);

                $existing = $this->find($data);

                if ($existing !== null) {
                    return ['computer' => $this->adoptMac($existing, $mac)->load('zone'), 'created' => false];
                }

                // A new MAC is a new PC even if its UUID is on another card (PCs of one image,
                // a board vendor placeholder): the UUID stays with that card, this one is known by its MAC.
                if ($mac !== null && $deviceId !== null && $deviceId !== ''
                    && ClubComputer::query()->where('device_id', $deviceId)->exists()) {
                    $deviceId = MacAddress::deviceId($mac);
                }

                $computerLimit = data_get($club->subscription?->plan?->features, 'max_computers');
                if (is_numeric($computerLimit) && ClubComputer::query()->count() >= (int) $computerLimit) {
                    throw ValidationException::withMessages(['device_name' => __('Достигнут лимит компьютеров по тарифу клуба.')]);
                }

                $zone = isset($data['zone_id'])
                    ? ClubZone::query()->findOrFail($data['zone_id'])
                    : $this->defaultZone();

                if ($zone->capacity !== null && $zone->computers()->count() >= $zone->capacity) {
                    throw ValidationException::withMessages(['zone_id' => __('В зоне достигнут лимит мест.')]);
                }

                $computer = $zone->computers()->create([
                    'number' => ((int) $zone->computers()->max('number')) + 1,
                    'device_name' => $data['device_name'],
                    'device_id' => $deviceId,
                    'mac_address' => $mac,
                    'status' => 'available',
                ]);
                $this->activity->record('computer.registered', __('Агент зарегистрировал ПК :computer в зоне :zone', ['computer' => $computer->displayName(), 'zone' => $zone->name]), $actorId, $computer, [
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

            $existing = $this->find($data);

            if ($existing !== null) {
                return ['computer' => $existing->load('zone'), 'created' => false];
            }

            throw new ConflictHttpException(__('Этот device_id уже зарегистрирован.'), $exception);
        }
    }

    /**
     * The card of the PC that sends this request. With a MAC only the card of that MAC is
     * this PC: the hostname, Windows ids and often the UUID come from the boot image and are
     * the same on every PC of the club. A card with a UUID and no MAC yet (registered by an
     * older agent) is taken over by the first PC that reports that UUID with its MAC.
     * Without a MAC (older agents): by device_id, then by the PC name.
     *
     * @param  array{device_name:string, device_id?:?string, mac_address?:?string}  $data
     */
    public function find(array $data): ?ClubComputer
    {
        $deviceId = trim((string) ($data['device_id'] ?? ''));
        $mac = MacAddress::normalize($data['mac_address'] ?? null);

        if ($mac !== null) {
            return ClubComputer::query()->where('mac_address', $mac)->first()
                ?? ($deviceId === '' ? null : ClubComputer::query()
                    ->whereRaw('LOWER(device_id) = ?', [mb_strtolower($deviceId)])
                    ->whereNull('mac_address')
                    ->first());
        }

        return $deviceId !== ''
            ? ClubComputer::query()->whereRaw('LOWER(device_id) = ?', [mb_strtolower($deviceId)])->first()
            : ClubComputer::query()
                ->whereRaw('LOWER(device_name) = ?', [mb_strtolower($data['device_name'])])
                ->first();
    }

    /** A card found without a MAC gets the MAC of its PC; a MAC already on the card is never changed here. */
    public function adoptMac(ClubComputer $computer, ?string $mac): ClubComputer
    {
        if ($mac !== null && $computer->mac_address === null) {
            $computer->forceFill(['mac_address' => $mac])->save();
        }

        return $computer;
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
