<?php

namespace App\Http\Controllers;

use App\Models\ClubMembership;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use App\Support\ClubPermissions;
use App\Support\ClubRoles;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Settings → Rights: pick an employee, then tick what they may do (section × action). A right left
 * as the role has it is not stored, so the employee follows the role (ClubPermissions::SECTIONS).
 * The owner sets anyone's rights; an admin — of managers, cashiers and operators, not their own.
 */
class PermissionController extends Controller
{
    public function __construct(private CurrentClub $currentClub) {}

    public function index(): View
    {
        $staff = ClubMembership::query()
            ->whereIn('role', array_diff(ClubRoles::STAFF, ['owner']))
            ->with('user:id,name,phone')
            ->orderByRaw("CASE role WHEN 'admin' THEN 1 WHEN 'manager' THEN 2 WHEN 'cashier' THEN 3 ELSE 4 END")
            ->orderBy('id')
            ->get();

        return view('settings.permissions', ['staff' => $staff, 'editable' => fn (ClubMembership $member): bool => $this->mayEdit($member)]);
    }

    public function edit(ClubMembership $membership): View
    {
        $this->authorizeFor($membership);

        return view('settings.permissions-edit', [
            'member' => $membership->load('user:id,name,phone'),
            'sections' => ClubPermissions::sections(),
            'actions' => ClubPermissions::actionLabels(),
        ]);
    }

    public function update(Request $request, ClubMembership $membership, ActivityLogger $activity): RedirectResponse
    {
        $this->authorizeFor($membership);
        $sent = $request->validate(['rights' => ['array'], 'rights.*' => ['array'], 'rights.*.*' => ['boolean']])['rights'] ?? [];
        $sections = ClubPermissions::sections();
        $actions = ClubPermissions::actionLabels();

        $given = [];
        $changes = [];
        foreach (ClubPermissions::all() as $right) {
            [$section, $action] = explode('.', $right);
            $after = filter_var($sent[$section][$action] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($after !== ClubPermissions::byRole($membership->role, $right)) {
                $given[$right] = $after;
            }
            if ($after !== ClubPermissions::allows($membership, $right)) {
                $changes[$sections[$section]['label'].': '.$actions[$action]] = $after ? __('да') : __('нет');
            }
        }
        $membership->forceFill(['permissions' => $given === [] ? null : $given])->save();
        if ($changes !== []) {
            $activity->record('permissions.updated', __('Изменены права сотрудника: :user', ['user' => $membership->user->name]), $request->user()->getKey(), $membership->user, $changes);
        }

        return redirect()->route('settings.permissions.edit', $membership)->with('status', __('Права сохранены.'));
    }

    /** Back to the rights of the role. */
    public function reset(Request $request, ClubMembership $membership, ActivityLogger $activity): RedirectResponse
    {
        $this->authorizeFor($membership);
        $membership->forceFill(['permissions' => null])->save();
        $activity->record('permissions.updated', __('Права сотрудника по роли: :user', ['user' => $membership->user->name]), $request->user()->getKey(), $membership->user, ['reset' => true]);

        return redirect()->route('settings.permissions.edit', $membership)->with('status', __('Права сотрудника снова по роли.'));
    }

    private function mayEdit(ClubMembership $member): bool
    {
        $me = $this->currentClub->membership();
        if (! ClubRoles::isStaff($member->role) || $member->role === 'owner') {
            return false;
        }

        return $me?->role === 'owner' || ($me?->role === 'admin' && $member->role !== 'admin' && $member->id !== $me->id);
    }

    private function authorizeFor(ClubMembership $member): void
    {
        abort_unless(ClubRoles::isStaff($member->role) && $member->role !== 'owner', 404);
        abort_unless($this->mayEdit($member), 403, __('Права администратора задаёт только владелец клуба.'));
    }
}
