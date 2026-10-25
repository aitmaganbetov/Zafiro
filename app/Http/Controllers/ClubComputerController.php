<?php

namespace App\Http\Controllers;

use App\Http\Requests\ControlClubComputersRequest;
use App\Http\Requests\HeartbeatClubComputerRequest;
use App\Http\Requests\RegisterClubComputerRequest;
use App\Http\Requests\ResolveClubComputerRequest;
use App\Http\Requests\UpdateClubComputerLayoutRequest;
use App\Http\Requests\UpdateClubComputerRequest;
use App\Models\ChatMessage;
use App\Models\Club;
use App\Models\ClubComputer;
use App\Models\ClubZone;
use App\Models\Friendship;
use App\Models\Language;
use App\Models\MemberBalance;
use App\Services\ActivityLogger;
use App\Services\AgentTariffPurchaseService;
use App\Services\AgentUpdate;
use App\Services\ClubComputerRegistrar;
use App\Services\ClubComputerResolver;
use App\Services\ClubComputerSessions;
use App\Services\ClubZoneComputerService;
use App\Services\ComputerPower;
use App\Services\CurrentClub;
use App\Services\GuestFeatures;
use App\Services\Integrations\WebhookDispatcher;
use App\Services\LocaleResolver;
use App\Services\TranslationCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ClubComputerController extends Controller
{
    /** Heartbeat fields that are requests to the server, not the PC state: a heartbeat of only these is "empty". */
    private const NON_STATE_FIELDS = ['fresh_boot', 'can_update', 'commands'];

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

    /**
     * Removes the PC from the club map. Not while a paid session runs on it; its bookings and
     * QR codes go with it, its token stops working.
     */
    public function destroy(Request $request, ClubComputer $clubComputer, ActivityLogger $activity): RedirectResponse
    {
        $name = DB::transaction(function () use ($request, $clubComputer, $activity): string {
            $computer = ClubComputer::query()->lockForUpdate()->findOrFail($clubComputer->getKey());
            if ($computer->hasPaidSession()) {
                throw ValidationException::withMessages(['computer' => __('На ПК :computer идёт сессия: удалить его можно после её окончания.', ['computer' => $computer->displayName()])]);
            }

            $name = $computer->displayName();
            $activity->record('computer.deleted', __('Удалён ПК :computer', ['computer' => $name]), $request->user()->getKey(), null, [
                'computer' => $name,
                'number' => $computer->number,
                'mac_address' => $computer->mac_address,
            ]);
            $computer->delete();

            return $name;
        });

        return back()->with('status', __('ПК :computer удалён.', ['computer' => $name]))->with('edit_mode', true);
    }

    public function control(ControlClubComputersRequest $request, ActivityLogger $activity, WebhookDispatcher $webhooks, ComputerPower $power, ClubComputerSessions $sessions): JsonResponse
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

        // A PC being turned on stays offline until its agent reports; the others show the command.
        $status = match ($data['action']) {
            'power_on', 'service' => null,
            'restart' => 'restarting',
            'power_off' => 'offline',
        };

        $result = DB::transaction(function () use ($data, $status, $logControl, $webhooks, $power, $request, $sessions): array {
            if ($status !== null) {
                ClubComputer::query()->whereKey($data['computer_ids'])->update(['status' => $status]);
            }
            // The technical mode, as Ctrl+Alt+P with the staff PIN: the PC is a staff visit from now.
            if ($data['action'] === 'service') {
                foreach (ClubComputer::query()->whereKey($data['computer_ids'])->get() as $computer) {
                    $sessions->startStaffVisit($computer, $request->user(), 'panel');
                }
            }
            $result = $power->queue(array_values($data['computer_ids']), $data['action']);
            $logControl($data['action'], count($data['computer_ids']));
            $webhooks->dispatch('computer.controlled', ['computer_ids' => array_values($data['computer_ids']), 'action' => $data['action'], 'status' => $status]);

            return $result;
        });

        return response()->json([
            'updated' => count($data['computer_ids']),
            'status' => $status,
            ...$result,
            'message' => $this->controlMessage($data['action'], $result),
        ]);
    }

    /** @param  array<string, int>  $result */
    private function controlMessage(string $action, array $result): string
    {
        if ($action !== 'power_on') {
            $version = $action === 'service' ? ComputerPower::SERVICE_AGENT_VERSION : ComputerPower::AGENT_VERSION;
            $ready = $result['queued'] - $result['outdated'];
            if ($ready === 0) {
                return __('Агент на этих ПК устарел и не выполнит команду: обновите его до версии :version или новее.', ['version' => $version]);
            }
            $message = match ($action) {
                'restart' => trans_choice('Перезагрузка: ПК получит команду в течение минуты (:count ПК).|Перезагрузка: ПК получат команду в течение минуты (:count ПК).|Перезагрузка: ПК получат команду в течение минуты (:count ПК).', $ready),
                'service' => trans_choice('Техрежим: агент на ПК закроется в течение минуты, как по PIN-коду (:count ПК).|Техрежим: агент на ПК закроется в течение минуты, как по PIN-коду (:count ПК).|Техрежим: агент на ПК закроется в течение минуты, как по PIN-коду (:count ПК).', $ready),
                default => trans_choice('Выключение: ПК получит команду в течение минуты (:count ПК).|Выключение: ПК получат команду в течение минуты (:count ПК).|Выключение: ПК получат команду в течение минуты (:count ПК).', $ready),
            };

            return $result['outdated'] > 0
                ? $message.' '.__('На :count ПК агент устарел (нужна версия :version): они команду не выполнят.', ['count' => $result['outdated'], 'version' => $version])
                : $message;
        }

        if ($result['woken'] === 0) {
            return __('Не удалось включить: агент ещё не сообщил MAC-адрес этих ПК.');
        }

        if ($result['relays'] === 0) {
            return $result['old_relays'] > 0
                ? __('Включить некому: на включённых ПК клуба старый агент. Сигнал Wake-on-LAN отправляет агент версии :version или новее — обновите агент хотя бы на одном ПК.', ['version' => ComputerPower::AGENT_VERSION])
                : __('Включить некому: в клубе нет включённых ПК с агентом, который отправит сигнал Wake-on-LAN.');
        }

        return $result['without_mac'] > 0
            ? __('Сигнал включения отправят другие ПК клуба (Wake-on-LAN): :count ПК. Без MAC-адреса: :missing.', ['count' => $result['woken'], 'missing' => $result['without_mac']])
            : __('Сигнал включения отправят другие ПК клуба (Wake-on-LAN): :count ПК. В BIOS этих ПК должен быть включён Wake-on-LAN.', ['count' => $result['woken']]);
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

        return $this->heartbeatResponse($sessions->heartbeat($computer, $data, $request->ip()), array_diff_key($request->validated(), array_flip(self::NON_STATE_FIELDS)) === []);
    }

    /** New device token for this PC; the one in the request stops working. */
    public function rotateAgentToken(Request $request, ClubComputerSessions $sessions): JsonResponse
    {
        return response()->json(['agent_token' => $sessions->issueAgentToken($request->attributes->get('agent_computer'))]);
    }

    /** Heartbeat authenticated by the PC's device token (X-Agent-Token). */
    public function agentHeartbeat(HeartbeatClubComputerRequest $request, ClubComputerSessions $sessions, AgentUpdate $updates, ComputerPower $power): JsonResponse
    {
        $computer = $request->attributes->get('agent_computer');

        $result = $sessions->heartbeat($computer, $request->validated(), $request->ip());
        // Only agents that carry out staff power commands ask for them, so an older agent does not take one.
        $commands = $request->boolean('commands') ? [
            'power_command' => $power->take($result['computer']),
            'wake_macs' => $power->wakeTargets($result['computer']),
        ] : [];
        // The technical mode: the agent service keeps the agent closed this long (as after the PIN).
        if (($commands['power_command'] ?? null) === 'service') {
            $commands['staff_minutes'] = (int) (Club::query()->whereKey($computer->club_id)->value('agent_staff_minutes') ?? ClubComputerSessions::DEFAULT_STAFF_MINUTES);
        }

        return $this->heartbeatResponse(
            $result,
            array_diff_key($request->validated(), array_flip(self::NON_STATE_FIELDS)) === [],
            $this->customerState($request, $computer),
            $request->boolean('can_update') ? $updates->offerFor($result['computer']) : null,
            $commands,
        );
    }

    /**
     * The agent sends the signed-in customer's token with the heartbeat: their balance and what is
     * new for them ride on the answer, so the agent does not poll /api/me, friends and the chat.
     * Only a valid token of this club counts, and it shows only its owner's own data.
     *
     * @return array{user_id: int, balance: float, bonus_balance: float, friend_requests: int, chat_last_staff_id: int}|null
     */
    private function customerState(Request $request, ClubComputer $computer): ?array
    {
        if ($request->bearerToken() === null) {
            return null;
        }
        $user = Auth::guard('sanctum')->user();
        $token = $user?->currentAccessToken();
        if ($token === null || ! $token->can('club:'.$computer->club_id)) {
            return null;
        }
        $balance = MemberBalance::query()->where('user_id', $user->getKey())->first(['balance', 'bonus_balance']);

        return [
            'user_id' => $user->getKey(),
            'balance' => (float) ($balance?->balance ?? 0),
            'bonus_balance' => (float) ($balance?->bonus_balance ?? 0),
            'friend_requests' => Friendship::query()->where('addressee_id', $user->getKey())->whereNull('accepted_at')->count(),
            'chat_last_staff_id' => (int) ChatMessage::query()->where('customer_id', $user->getKey())->where('from_staff', true)->max('id'),
        ];
    }

    /** Staff end the sessions of the selected PCs (the map): unused time goes back as in the agent. */
    public function endSessions(Request $request, ClubComputerSessions $sessions): JsonResponse
    {
        $ids = $request->validate([
            'computer_ids' => ['required', 'array', 'min:1', 'max:200'],
            'computer_ids.*' => ['required', 'integer', 'distinct'],
        ])['computer_ids'];
        $ended = 0;
        foreach (ClubComputer::query()->whereKey($ids)->whereNotNull('current_session_id')->get() as $computer) {
            $sessions->end($computer, (string) $computer->current_session_id, $request->user(), true);
            $ended++;
        }

        return response()->json([
            'ended' => $ended,
            'message' => $ended === 0
                ? __('На выбранных ПК нет активной сессии.')
                : trans_choice('Сессия завершена на :count ПК. Неиспользованное время вернулось клиенту.|Сессии завершены на :count ПК. Неиспользованное время вернулось клиентам.|Сессии завершены на :count ПК. Неиспользованное время вернулось клиентам.', $ended),
        ]);
    }

    /** Ends the paid session early: its owner or club staff. */
    public function endSession(Request $request, ClubComputer $computer, ClubComputerSessions $sessions): JsonResponse
    {
        $data = $request->validate(['session_id' => ['required', 'string', 'max:255']]);
        $settled = $sessions->end($computer, $data['session_id'], $request->user(), Gate::allows('register', ClubComputer::class));

        return response()->json([
            'success' => true,
            'session' => null,
            // Unused minutes went back to the balances; a tariff with a usage limit was kept.
            'refunded' => AgentTariffPurchaseService::formatCents($settled['refund_main']),
            'refunded_bonus' => AgentTariffPurchaseService::formatCents($settled['refund_bonus']),
            'kept_minutes' => $settled['kept_minutes'],
        ]);
    }

    /**
     * @param  array{computer: ClubComputer, session: ?array, session_expired: bool}  $result
     * @param  array{version: string, url: string, sha256: string}|null  $agentUpdate  the installer the agent service installs itself
     * @param  array{power_command?: ?string, wake_macs?: list<string>}  $commands  staff power commands, for agents that ask
     */
    private function heartbeatResponse(array $result, bool $emptyHeartbeat, ?array $customer = null, ?array $agentUpdate = null, array $commands = []): JsonResponse
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
                // The club's picture on the sign-in screen; null — the standard one.
                'signin_image_url' => app(CurrentClub::class)->get()?->signInImageUrl(),
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
            // The signed-in customer (when the agent sent their token): balance and counters.
            'customer' => $customer,
            // A newer agent the service installs itself while the PC is free (see AgentUpdate::offerFor).
            'agent_update' => $agentUpdate,
            ...$commands,
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
