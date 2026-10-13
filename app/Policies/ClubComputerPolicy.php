<?php

namespace App\Policies;

use App\Models\ClubComputer;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Auth\Access\Response;

class ClubComputerPolicy
{
    public function heartbeat(User $user, ClubComputer $computer): bool
    {
        return (int) $computer->club_id === app(CurrentClub::class)->id();
    }

    public function register(User $user): Response
    {
        $role = $user->currentMembership()->value('role');

        return in_array($role, ['owner', 'admin', 'manager', 'operator'], true)
            ? Response::allow()
            : Response::deny('Недостаточно прав для регистрации компьютера в клубе.');
    }
}