<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The first rights were whole sections; now a section and an action ("warehouse.create"). */
    private const MAP = [
        'cash_register' => ['cash.view', 'cash.create'],
        'cash_refunds' => ['cash.delete'],
        'customer_money' => ['customer_money.update'],
        'club_map_edit' => ['club_map.create', 'club_map.update', 'club_map.delete'],
        'tariffs' => ['tariffs.view', 'tariffs.create', 'tariffs.update'],
        'warehouse' => ['warehouse.view', 'warehouse.create', 'warehouse.update'],
        'activity_logs' => ['activity_logs.view'],
        'settings' => ['settings.view', 'settings.update'],
        'news' => ['news.view', 'news.create', 'news.update', 'news.delete'],
        'todos_manage' => ['todos.create', 'todos.delete'],
        'billing' => ['billing.view'],
        'workers' => ['workers.view', 'workers.create', 'workers.update', 'workers.delete'],
    ];

    public function up(): void
    {
        DB::table('club_memberships')->whereNotNull('permissions')->orderBy('id')->each(function (object $membership): void {
            $old = json_decode((string) $membership->permissions, true);
            $new = [];
            foreach (is_array($old) ? $old : [] as $right => $value) {
                foreach (self::MAP[$right] ?? [] as $key) {
                    $new[$key] = (bool) $value;
                }
            }
            DB::table('club_memberships')->where('id', $membership->id)->update(['permissions' => $new === [] ? null : json_encode($new)]);
        });
    }

    public function down(): void {}
};
