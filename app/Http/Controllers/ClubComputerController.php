<?php

namespace App\Http\Controllers;

use App\Http\Requests\ControlClubComputersRequest;
use App\Http\Requests\HeartbeatClubComputerRequest;
use App\Http\Requests\RegisterClubComputerRequest;
use App\Http\Requests\ResolveClubComputerRequest;
use App\Http\Requests\UpdateClubComputerLayoutRequest;
use App\Http\Requests\UpdateClubComputerRequest;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\Language;
use App\Services\ActivityLogger;
use App\Services\ClubComputerRegistrar;
use App\Services\ClubComputerResolver;
use App\Services\ClubComputerSessions;
use App\Services\ClubZoneComputerService;
use App\Services\CurrentClub;
use App\Services\GuestFeatures;
use App\Services\Integrations\WebhookDispatcher;
use App\Services\LocaleResolver;
use App\Services\TranslationCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ClubComputerController extends Controller
{
    public function store(Request $request, ClubZone $clubMap, ClubZoneComputerService $computers, ActivityLogger $activity): RedirectResponse
    {
        abort_unless($clubMap->icon === 'desktop', 404);

        $computer = DB::transaction(function () use ($clubMap, $computers, $activity, $request): ?ClubComputer {
            $computer = $computers->add($clubMap);

            if ($computer !== null) {
                $activity->record('computer.created', __('Добавлен ПК :computer в зону :zone', ['computer' => $computer->displayName(), 'zone' => $clubMap->name]), $request->user()->getKey(), $computer, [
                    'zone' => $clubMap->name,
                    'number' => $computer->number,
                ]);
            }

            return $computer;
        });
        $status = $computer === null ? __('Достигнута вместимость зоны.') : __('Компьютер добавлен в зону.');

        return back()->with('status', $status)->with('edit_mode', true);
    }

    public function update(UpdateClubComputerRequest $request, ClubComputer $clubComputer, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validated();
        $zoneChanged = (int) $data['club_zone_id'] !== $clubComputer->club_zone_id;

        DB::transaction(function () use ($data, $zoneChanged, $clubComputer, $activity, $request): void {
            $original = $clubComputer->getOriginal();

            if ($zoneChanged) {
                $zone = ClubZone::query()->whereKey($data['club_zone_id'])->lockForUpdate()->firstOrFail();
                if ($zone->capacity !== null && $zone->computers()->count() >= $zone->capacity) {
                    throw ValidationException::withMessages([
                        'club_zone_id' => __('Достигнута вместимость зоны.'),
                    ]);
                }

                $occupied = $zone->computers()
                    ->whereKeyNot($clubComputer->getKey())
                    ->get(['position_x', 'position_y'])
                    ->mapWithKeys(fn (ClubComputer $computer): array => ["{$computer->position_x}:{$computer->position_y}" => true]);
                $position = null;
                $maxX = max(0, $zone->width - 66);
                $maxY = max(0, $zone->height - 166);

                for ($y = 5; $y <= $maxY && $position === null; $y += 42) {
                    for ($x = 5; $x <= $maxX; $x += 47) {
                        if (! $occupied->has("{$x}:{$y}")) {
                            $position = ['position_x' => $x, 'position_y' => $y];
                            break;
                        }
                    }
                }

                if ($position === null) {
                    throw ValidationException::withMessages([
                        'club_zone_id' => __('В выбранной зоне нет свободного места.'),
                    ]);
                }

                $data = [...$data, ...$position];
            }

            $clubComputer->update($data);
            $changes = collect($clubComputer->getChanges())
                ->except('updated_at')
                ->map(fn (mixed $value, string $field): string => ($original[$field] ?? '—').' → '.($value ?? '—'))
                ->all();

            if ($changes !== []) {
                $activity->record('computer.updated', __('Изменён компьютер: :computer', ['computer' => $clubComputer->displayName()]), $request->user()->getKey(), $clubComputer, $changes);
            }
        });

        return back()->with('status', __('Данные компьютера обновлены.'))->with('edit_mode', true);
    }

    public function control(ControlClubComputersRequest $request, ActivityLogger $activity, WebhookDispatcher $webhooks): JsonResponse
    {
        $data = $request->validated();
        $logControl = function (string $action, int $updated) use ($data, $request, $activity): void {
            $names = ClubComputer::query()->whereKey($data['computer_ids'])->orderBy('number')->get()
                ->map(fn (ClubComputer $computer): string => $computer->displayName())->implode(', ');
            $activity->record('computer.controlled', __('Управление ПК (:action): :names', ['action' => $action, 'names' => $names]), $request->user()->getKey(), null, [
                'action' => $action,
                'computers' => $names,
                'updated' => $updated,
            ]);
        };

        if ($data['action'] === 'reserve') {
            $computers = ClubComputer::query()->whereKey($data['computer_ids'])->get();
            $reserved = $computers->contains(fn (ClubComputer $computer): bool => ! $computer->is_reserved);
            $updated = DB::transaction(function () use ($data, $reserved, $logControl, $webhooks): int {
                $updated = ClubComputer::query()->whereKey($data['computer_ids'])->update(['is_reserved' => $reserved]);
                $logControl($reserved ? 'reserve' : 'unreserve', $updated);
                $webhooks->dispatch('computer.reservation_changed', ['computer_ids' => array_values($data['computer_ids']), 'reserved' => $reserved]);

                return $updated;
            });
            $statuses = ClubComputer::query()->whereKey($data['computer_ids'])->get()
                ->map(fn (ClubComputer $computer): array => ['id' => $computer->id, 'status' => $computer->currentStatus()])
                ->values();

            return response()->json(['updated' => $updated, 'reserved' => $reserved, 'computers' => $statuses]);
        }

        $status = match ($data['action']) {
            'power_on' => 'online',
            'restart' => 'restarting',
            'power_off' => 'offline',
        };

        $updated = DB::transaction(function () use ($data, $status, $logControl, $webhooks): int {
            $updated = ClubComputer::query()->whereKey($data['computer_ids'])->update(['status' => $status]);
            $logControl($data['action'], $updated);
            $webhooks->dispatch('computer.controlled', ['computer_ids' => array_values($data['computer_ids']), 'action' => $data['action'], 'status' => $status]);

            return $updated;
        });

        return response()->json(['updated' => $updated, 'status' => $status]);
    }

    public function resolve(ResolveClubComputerRequest $request, ClubComputerResolver $computers, ClubComputerSessions $sessions): JsonResponse
    {
        $computer = $computers->resolve(
            $request->validated('device_name'),
            $request->validated('device_id'),
            $request->validated('mac_address'),
        );

        if ($computer === null) {
            return response()->json([
                'code' => 'computer_not_found',
                'message' => __('Компьютер не найден в текущем клубе.'),
            ], 404);
        }

        return response()->json($this->computerIdentity($computer) + $this->agentToken($computer, $sessions, $request->boolean('claim', true)));
    }

    public function register(RegisterClubComputerRequest $request, ClubComputerRegistrar $computers, ClubComputerSessions $sessions): JsonResponse
    {
        Gate::authorize('register', ClubComputer::class);
        $result = $computers->register($request->validated(), $request->user()->getKey());

        return response()->json(
            $this->computerIdentity($result['computer']) + $this->agentToken($result['computer'], $sessions, $request->boolean('claim', true)),
            $result['created'] ? 201 : 200,
        );
    }

    /** Heartbeat with a user token (agents before 1.1); same rules as the device heartbeat. */
    public function heartbeat(HeartbeatClubComputerRequest $request, string $computer, ClubComputerSessions $sessions): JsonResponse
    {
        $computer = ClubComputer::query()->withoutGlobalScope('club')->findOrFail($computer);
        Gate::authorize('heartbeat', $computer);

        // A PC with a device token reports only through it: a customer's token must not mark
        // this (or any other) PC of the club online or busy.
        if ($computer->agent_token_hash !== null) {
            return response()->json(['message' => __('Этот ПК отправляет статус токеном устройства.'), 'code' => 'use_device_token'], 403);
        }
        $data = $request->validated();

        // A customer's token may report the agent's state, but not rename the PC or its hardware.
        if (! Gate::allows('register', ClubComputer::class)) {
            $data = array_intersect_key($data, array_flip(['state', 'session_id', 'session_ends_at', 'agent_version']));
        }

        return $this->heartbeatResponse($sessions->heartbeat($computer, $data, $request->ip()), array_diff_key($request->validated(), ['fresh_boot' => true]) === []);
    }

    /** New device token for this PC; the one in the request stops working. */
    public function rotateAgentToken(Request $request, ClubComputerSessions $sessions): JsonResponse
    {
        return response()->json(['agent_token' => $sessions->issueAgentToken($request->attributes->get('agent_computer'))]);
    }

    /** Heartbeat authenticated by the PC's device token (X-Agent-Token). */
    public function agentHeartbeat(HeartbeatClubComputerRequest $request, ClubComputerSessions $sessions): JsonResponse
    {
        $computer = $request->attributes->get('agent_computer');

        return $this->heartbeatResponse($sessions->heartbeat($computer, $request->validated(), $request->ip()), array_diff_key($request->validated(), ['fresh_boot' => true]) === []);
    }

    /** Ends the paid session early: its owner or club staff. */
    public function endSession(Request $request, ClubComputer $computer, ClubComputerSessions $sessions): JsonResponse
    {
        $data = $request->validate(['session_id' => ['required', 'string', 'max:255']]);
        $sessions->end($computer, $data['session_id'], $request->user(), Gate::allows('register', ClubComputer::class));

        return response()->json(['success' => true, 'session' => null]);
    }

    /** @param  array{computer: ClubComputer, session: ?array, session_expired: bool}  $result */
    private function heartbeatResponse(array $result, bool $emptyHeartbeat): JsonResponse
    {
        $computer = $result['computer'];
        $computer->loadMissing('zone');
        $recommendedInterval = max(5, (int) config('club.computer_heartbeat_interval_seconds', 30));
        $connectionStatus = $computer->connectionStatus();

        return response()->json([
            ...$this->computerIdentity($computer),
            'status' => $emptyHeartbeat ? $computer->currentStatus() : $connectionStatus,
            'connection_status' => $connectionStatus,
            'hardware' => [
                'mac_address' => $computer->mac_address,
                'local_ip' => $computer->local_ip,
                'remote_ip' => $computer->remote_ip,
                'cpu_model' => $computer->cpu_model,
                'cpu_cores' => $computer->cpu_cores,
                'ram_total_mb' => $computer->ram_total_mb,
                'gpu_model' => $computer->gpu_model,
                'os_version' => $computer->os_version,
                'reported_at' => $computer->hardware_reported_at?->utc()->toIso8601String(),
            ],
            'session' => $result['session'],
            'session_expired' => $result['session_expired'],
            // Shown by the agent on the locked monitors.
            'club' => [
                'name' => app(CurrentClub::class)->get()?->name,
                'logo_url' => app(CurrentClub::class)->get()?->logoUrl(),
                ...$this->agentLanguages(),
                'after_session' => $this->afterSessionAction(),
                // What customers may do themselves (club settings → Guests).
                'guest_features' => [...app(GuestFeatures::class)->all(), 'shop' => app(GuestFeatures::class)->shopEnabled()],
                // A customer signed in without a paid session is signed out after this many minutes.
                'idle_logout_minutes' => app(GuestFeatures::class)->idleLogoutMinutes(),
            ],
            'last_seen_at' => $computer->last_seen_at->utc()->toIso8601String(),
            // The agent counts the session end against the server clock, not the PC's.
            'server_time' => now()->utc()->format('Y-m-d\TH:i:s.vP'),
            'recommended_interval_seconds' => $recommendedInterval,
            'heartbeat_interval_seconds' => $recommendedInterval,
        ]);
    }

    /**
     * Staff who set up a PC get its device token (shown once, replaces the previous one);
     * customers resolving a PC never do. The agent asks for it ("claim") only while it has no
     * token, so a staff member signing in again is an ordinary sign-in; agents that do not
     * send "claim" (before 1.0.1) get the token on every staff resolve, as before.
     * A staff claim also marks the PC as in staff hands.
     *
     * @return array{agent_token?: string}
     */
    private function agentToken(ClubComputer $computer, ClubComputerSessions $sessions, bool $claim = true): array
    {
        if (! $claim || ! Gate::allows('register', ClubComputer::class)) {
            return [];
        }

        $staff = request()->user();
        $sessions->startStaffVisit($computer, $staff, 'signin');
        app(ActivityLogger::class)->record('agent.staff_signin', __('Сотрудник :user вошёл на ПК :computer', ['user' => $staff->name, 'computer' => $computer->displayName()]), $staff->getKey(), $computer);

        return ['agent_token' => $sessions->issueAgentToken($computer)];
    }

    /**
     * What the agent does with the PC once a session ends and the customer has not paid for
     * a new one within the delay (club settings): nothing, restart or shut down.
     *
     * @return array{action: string, delay_seconds: int}
     */
    private function afterSessionAction(): array
    {
        $settings = app(CurrentClub::class)->settings()['club'] ?? [];
        $action = $settings['pc_action_after_session_end'] ?? 'none';
        $minutes = $settings['pc_restart_after_session_end'] ?? 0.5;

        return [
            'action' => in_array($action, ['restart', 'shutdown'], true) ? $action : 'none',
            'delay_seconds' => is_numeric($minutes) ? (int) round(max(0, (float) $minutes) * 60) : 30,
        ];
    }

    /**
     * The agent shows the club language until a customer picks another one, offers the enabled
     * languages, and refetches its texts when the translations version changes.
     *
     * @return array{locale: ?string, languages: list<array{code: string, name: string}>, translations_version: int}
     */
    private function agentLanguages(): array
    {
        $club = app(CurrentClub::class)->get();
        $enabled = app(LocaleResolver::class)->enabledCodes();

        return [
            'locale' => $club === null ? null : app(LocaleResolver::class)->forClub($club),
            'languages' => Language::query()->whereIn('code', $enabled)->orderBy('sort_order')->orderBy('id')->get(['code', 'name'])
                ->map(fn (Language $language): array => ['code' => $language->code, 'name' => $language->name])->all(),
            'translations_version' => app(TranslationCatalog::class)->version(),
        ];
    }

    private function computerIdentity(ClubComputer $computer): array
    {
        $computer->loadMissing('zone');

        return [
            'computer_id' => $computer->getKey(),
            'number' => (string) $computer->number,
            'name' => $computer->displayName(),
            'zone' => [
                'id' => $computer->zone->getKey(),
                'name' => $computer->zone->name,
            ],
        ];
    }

    public function __invoke(UpdateClubComputerLayoutRequest $request, ClubComputer $clubComputer): JsonResponse
    {
        $layout = $request->validated();
        $occupied = ClubComputer::query()
            ->where('club_zone_id', $clubComputer->club_zone_id)
            ->whereKeyNot($clubComputer->getKey())
            ->where('position_x', $layout['position_x'])
            ->where('position_y', $layout['position_y'])
            ->exists();

        if ($occupied) {
            return response()->json(['message' => __('Эта ячейка уже занята другим компьютером.')], 409);
        }

        $clubComputer->update($layout);

        return response()->json(['saved' => true]);
    }
}
