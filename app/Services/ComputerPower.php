<?php

namespace App\Services;

use App\Models\ClubComputer;

/**
 * Turning club PCs off, on and restarting them from the panel, the staff app and API v1.
 * A command for a running PC waits on its card until the agent takes it with its heartbeat;
 * a command left untaken (the PC went offline) expires and does not fire on the next boot.
 * A PC that is off is woken by Wake-on-LAN: the agents of the club's running PCs get its MAC
 * with their heartbeat and send the magic packet in the club's network.
 */
class ComputerPower
{
    /** How long a command waits for the agent, and how long the running PCs try to wake a PC. */
    public const COMMAND_TTL_SECONDS = 120;

    /** The first agent version that asks for commands and carries them out. */
    public const AGENT_VERSION = '0.0.66';

    /** The first agent version that opens the technical mode on a command (as the staff PIN does). */
    public const SERVICE_AGENT_VERSION = '0.0.72';

    /** The first agent version that shows a message from the club map. */
    public const MESSAGE_AGENT_VERSION = '0.0.73';

    /** How long a message waits for the agent: a PC that is off does not show it on the next boot. */
    public const MESSAGE_TTL_SECONDS = 300;

    /** Agent commands by panel action. */
    private const COMMANDS = ['restart' => 'restart', 'power_off' => 'shutdown', 'service' => 'service'];

    /**
     * @param  list<int>  $ids
     * @return array{woken: int, without_mac: int, relays: int, old_relays: int}|array{queued: int, outdated: int}
     */
    public function queue(array $ids, string $action): array
    {
        if ($action !== 'power_on') {
            $queued = ClubComputer::query()->whereKey($ids)
                ->update(['power_command' => self::COMMANDS[$action], 'power_command_at' => now()]);
            $outdated = ClubComputer::query()->whereKey($ids)->pluck('agent_version')
                ->filter(fn (?string $version): bool => ! $this->carriesOut($version, $action))->count();

            return ['queued' => $queued, 'outdated' => $outdated];
        }

        $woken = ClubComputer::query()->whereKey($ids)->whereNotNull('mac_address')->update(['wake_requested_at' => now()]);
        // Only a running PC with an agent that asks for commands sends the Wake-on-LAN packet.
        $running = ClubComputer::query()->whereKeyNot($ids)
            ->where('last_seen_at', '>=', now()->subSeconds(ClubComputer::HEARTBEAT_TIMEOUT_SECONDS))
            ->pluck('agent_version');
        $relays = $running->filter(fn (?string $version): bool => $this->carriesOut($version))->count();

        return ['woken' => $woken, 'without_mac' => count($ids) - $woken, 'relays' => $relays, 'old_relays' => $running->count() - $relays];
    }

    /**
     * A message for the selected PCs: each agent shows it once, with its next heartbeat.
     *
     * @param  list<int>  $ids
     * @return array{queued: int, outdated: int}
     */
    public function message(array $ids, string $text): array
    {
        $queued = ClubComputer::query()->whereKey($ids)->update(['staff_message' => $text, 'staff_message_at' => now()]);
        $outdated = ClubComputer::query()->whereKey($ids)->pluck('agent_version')
            ->filter(fn (?string $version): bool => ! $this->carriesOut($version, 'message'))->count();

        return ['queued' => $queued, 'outdated' => $outdated];
    }

    /** The message for this PC, given once: the first heartbeat that takes it clears it. */
    public function takeMessage(ClubComputer $computer): ?string
    {
        $message = $computer->staff_message;

        if ($message === null) {
            return null;
        }

        $taken = ClubComputer::query()->whereKey($computer->getKey())
            ->where('staff_message', $message)
            ->where('staff_message_at', '>=', now()->subSeconds(self::MESSAGE_TTL_SECONDS))
            ->update(['staff_message' => null, 'staff_message_at' => null]);

        if ($taken === 0) {
            ClubComputer::query()->whereKey($computer->getKey())->where('staff_message', $message)
                ->update(['staff_message' => null, 'staff_message_at' => null]);

            return null;
        }

        return $message;
    }

    /** The agent of this version takes staff commands (this one, when given) with its heartbeat. */
    public function carriesOut(?string $version, ?string $action = null): bool
    {
        $minimum = match ($action) {
            'service' => self::SERVICE_AGENT_VERSION,
            'message' => self::MESSAGE_AGENT_VERSION,
            default => self::AGENT_VERSION,
        };

        return $version !== null && ! app(AgentUpdate::class)->isOutdated($version, $minimum);
    }

    /** The command for this PC, given once: the first heartbeat that takes it clears it. */
    public function take(ClubComputer $computer): ?string
    {
        $command = $computer->power_command;

        if ($command === null) {
            return null;
        }

        $taken = ClubComputer::query()->whereKey($computer->getKey())
            ->where('power_command', $command)
            ->where('power_command_at', '>=', now()->subSeconds(self::COMMAND_TTL_SECONDS))
            ->update(['power_command' => null, 'power_command_at' => null]);

        if ($taken === 0) {
            // Expired: the PC was off when it was given.
            ClubComputer::query()->whereKey($computer->getKey())->where('power_command', $command)
                ->update(['power_command' => null, 'power_command_at' => null]);

            return null;
        }

        return $command;
    }

    /**
     * MACs of the club's PCs staff asked to turn on that are still off: this PC sends them the
     * Wake-on-LAN packet.
     *
     * @return list<string>
     */
    public function wakeTargets(ClubComputer $relay): array
    {
        return ClubComputer::query()
            ->where('wake_requested_at', '>=', now()->subSeconds(self::COMMAND_TTL_SECONDS))
            ->whereKeyNot($relay->getKey())
            ->whereNotNull('mac_address')
            ->where(fn ($query) => $query->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<', now()->subSeconds(ClubComputer::HEARTBEAT_TIMEOUT_SECONDS)))
            ->limit(50)
            ->pluck('mac_address')
            ->all();
    }
}
