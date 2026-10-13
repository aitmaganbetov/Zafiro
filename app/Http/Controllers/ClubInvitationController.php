<?php

namespace App\Http\Controllers;

use App\Models\ClubInvitation;
use App\Models\ClubMembership;
use App\Models\MemberBalance;
use App\Models\User;
use App\Services\CurrentClub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ClubInvitationController extends Controller
{
    public function __construct(private CurrentClub $currentClub) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'role' => ['required', 'in:admin,manager,cashier,operator'],
        ]);
        $inviterRole = $request->user()->currentMembership()->value('role');
        abort_if($inviterRole === 'manager' && $validated['role'] === 'admin', 403);

        $token = Str::random(64);
        ClubInvitation::query()->create([
            'email' => $validated['email'] ?? null,
            'role' => $validated['role'],
            'token_hash' => hash('sha256', $token),
            'invited_by' => $request->user()->getKey(),
            'expires_at' => now()->addDays(7),
        ]);

        return redirect()->route('users.index')
            ->with('invite_url', route('club-invitations.show', $token))
            ->with('status', 'Ссылка-приглашение создана. Она действует 7 дней.');
    }

    public function show(Request $request, string $token): View
    {
        $invitation = $this->findValidInvitation($token);

        return view('invitations.accept', [
            'invitation' => $invitation,
            'alreadyMember' => $request->user() !== null && ClubMembership::query()
                ->withoutGlobalScope('club')
                ->where('club_id', $invitation->club_id)
                ->where('user_id', $request->user()->getKey())
                ->exists(),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->findValidInvitation($token);
        $user = $request->user();
        $validated = [];

        if ($user === null) {
            $digits = preg_replace('/\D+/', '', (string) $request->input('phone', ''));
            if (strlen($digits) === 11 && str_starts_with($digits, '7')) {
                $digits = substr($digits, 1);
            }
            $request->merge(['phone' => '+7'.$digits]);

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'phone' => ['required', 'regex:/^\+7\d{10}$/', 'unique:users,phone'],
                'password' => ['required', 'string', 'min:6', 'confirmed'],
            ]);
        } elseif ($invitation->email !== null && $user->email !== $invitation->email) {
            abort(403, 'Это приглашение предназначено для другого email.');
        }

        DB::transaction(function () use ($request, $invitation, $user, $validated): void {
            $lockedInvitation = ClubInvitation::query()
                ->withoutGlobalScope('club')
                ->lockForUpdate()
                ->findOrFail($invitation->getKey());
            abort_if($lockedInvitation->accepted_at !== null || $lockedInvitation->expires_at->isPast(), 410);

            $acceptingUser = $user ?? User::query()->create([
                'name' => $validated['name'],
                'phone' => $validated['phone'],
                'email' => $invitation->email,
                'password' => Hash::make($validated['password']),
            ]);

            abort_if(ClubMembership::query()
                ->withoutGlobalScope('club')
                ->where('club_id', $invitation->club_id)
                ->where('user_id', $acceptingUser->getKey())
                ->exists(), 409, 'Аккаунт уже состоит в этом клубе.');

            $this->currentClub->set($invitation->club);
            $invitation->club->memberships()->create([
                'user_id' => $acceptingUser->getKey(),
                'role' => $invitation->role,
            ]);
            MemberBalance::query()->create(['user_id' => $acceptingUser->getKey()]);
            $lockedInvitation->update(['accepted_at' => now()]);

            if ($user === null) {
                Auth::login($acceptingUser);
                $request->session()->regenerate();
            }
            $request->session()->put('current_club_id', $invitation->club_id);
        });

        return redirect()->route('dashboard')->with('status', 'Вы присоединились к клубу.');
    }

    private function findValidInvitation(string $token): ClubInvitation
    {
        return ClubInvitation::query()
            ->withoutGlobalScope('club')
            ->with('club')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();
    }
}