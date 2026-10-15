<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Models\ClubMembership;
use App\Models\MemberBalance;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\ClubRoles;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(): View
    {
        // Customers only; staff are on the Workers page.
        $users = ClubMembership::query()
            ->whereNotIn('role', ClubRoles::STAFF)
            ->with(['user:id,name,phone', 'balance'])
            ->orderBy('user_id')
            ->paginate(25);

        return view('users', ['users' => $users]);
    }

    public function store(StoreUserRequest $request, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validated();
        $actorId = $request->user()->getKey();
        DB::transaction(function () use ($data, $actorId, $activity): void {
            $user = User::query()->create(Arr::only($data, ['name', 'phone', 'email', 'password']));
            $membership = ClubMembership::query()->create([
                'user_id' => $user->getKey(),
                'role' => ClubRoles::CLIENT,
                'discount' => $data['discount'] ?? 0,
            ]);
            MemberBalance::query()->create([
                'user_id' => $user->getKey(),
                'balance' => $data['balance'] ?? 0,
                'bonus_balance' => $data['bonus_balance'] ?? 0,
            ]);
            $activity->record('user.created', __('Создан пользователь: :user', ['user' => $user->name]), $actorId, $user, [
                'phone' => $user->phone,
                'role' => $membership->role,
            ]);
        });

        return redirect()->route('users.index')->with('status', __('Пользователь добавлен.'));
    }
}
