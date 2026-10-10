<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Services\ActivityLogger;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class UserController extends Controller
{
    public function index(): View
    {
        $users = User::query()
            ->select(['id', 'name', 'phone', 'role', 'discount', 'balance', 'bonus_balance'])
            ->orderBy('name')
            ->paginate(25);

        return view('users', ['users' => $users]);
    }

    public function store(StoreUserRequest $request, ActivityLogger $activity): RedirectResponse
    {
        $user = User::query()->create($request->validated());
        $activity->record('user.created', "Создан пользователь: {$user->name}", $request->user()?->getKey(), $user, [
            'phone' => $user->phone,
            'role' => $user->role,
        ]);

        return redirect()->route('users.index')->with('status', 'Пользователь добавлен.');
    }
}