<?php

namespace App\Http\Controllers;

use App\Models\ClubMembership;
use App\Services\ActivityLogger;
use App\Support\ClubPermissions;
use App\Support\ClubRoles;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Rights: the owner gives or takes a right from one employee. A right left as the
 * role has it is not stored, so the employee follows the role (ClubPermissions::DEFAULTS).
 */
class PermissionController extends Controller
{
    public function index(): View
    {
        return view('settings.permissions', ['staff' => $this->staff(), 'rights' => ClubPermissions::labels()]);
    }

    public function update(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $staff = $this->staff();
        $data = $request->validate([
            'rights' => ['array'],
            'rights.*' => ['array'],
            'rights.*.*' => ['boolean'],
        ]);
        $labels = ClubPermissions::labels();

        DB::transaction(function () use ($staff, $data, $labels, $request, $activity): void {
            foreach ($staff as $membership) {
                $sent = $data['rights'][$membership->id] ?? null;
                if (! is_array($sent)) {
                    continue;
                }
                $given = [];
                $changes = [];
                foreach (array_keys(ClubPermissions::DEFAULTS) as $right) {
                    $before = ClubPermissions::allows($membership, $right);
                    $after = filter_var($sent[$right] ?? false, FILTER_VALIDATE_BOOLEAN);
                    if ($after !== ClubPermissions::byRole($membership->role, $right)) {
                        $given[$right] = $after;
                    }
                    if ($after !== $before) {
                        $changes[$labels[$right]['label']] = $after ? __('да') : __('нет');
                    }
                }
                $membership->forceFill(['permissions' => $given === [] ? null : $given])->save();
                if ($changes !== []) {
                    $activity->record('permissions.updated', __('Изменены права сотрудника: :user', ['user' => $membership->user->name]), $request->user()->getKey(), $membership->user, $changes);
                }
            }
        });

        return redirect()->route('settings.permissions')->with('status', __('Права сохранены.'));
    }

    /** Back to the rights of the role. */
    public function reset(Request $request, ClubMembership $membership, ActivityLogger $activity): RedirectResponse
    {
        abort_unless($this->staff()->contains('id', $membership->id), 404);
        $membership->forceFill(['permissions' => null])->save();
        $activity->record('permissions.updated', __('Права сотрудника по роли: :user', ['user' => $membership->user->name]), $request->user()->getKey(), $membership->user, ['reset' => true]);

        return redirect()->route('settings.permissions')->with('status', __('Права сотрудника снова по роли.'));
    }

    /** @return Collection<int, ClubMembership> staff of the club except owners */
    private function staff(): Collection
    {
        return ClubMembership::query()
            ->whereIn('role', array_diff(ClubRoles::STAFF, ['owner']))
            ->with('user:id,name,phone')
            ->orderByRaw("CASE role WHEN 'admin' THEN 1 WHEN 'manager' THEN 2 WHEN 'cashier' THEN 3 ELSE 4 END")
            ->orderBy('id')
            ->get();
    }
}
