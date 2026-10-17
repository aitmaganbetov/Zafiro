<?php

use App\Support\MacAddress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The MAC identifies a club computer: one MAC — one card in the club. Stored MACs are brought
 * to AA:BB:CC:DD:EE:FF; when two cards of a club share a MAC (a PC booted from the image of
 * another one reported its MAC into that card), the card that reported it last keeps it and
 * the others lose it: their PCs get it back on the next registration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $kept = [];

        $computers = DB::table('club_computers')->whereNotNull('mac_address')
            ->orderByDesc('hardware_reported_at')->orderByDesc('id')
            ->get(['id', 'club_id', 'mac_address']);

        foreach ($computers as $computer) {
            $mac = MacAddress::normalize($computer->mac_address);
            $key = $computer->club_id.'|'.$mac;

            if ($mac !== null && ! isset($kept[$key])) {
                $kept[$key] = true;
            } else {
                $mac = null;
            }

            if ($mac !== $computer->mac_address) {
                DB::table('club_computers')->where('id', $computer->id)->update(['mac_address' => $mac]);
            }
        }

        Schema::table('club_computers', function (Blueprint $table) {
            $table->unique(['club_id', 'mac_address'], 'club_computers_club_mac_unique');
            $table->dropIndex('club_computers_club_mac_index');
        });
    }

    public function down(): void
    {
        Schema::table('club_computers', function (Blueprint $table) {
            $table->index(['club_id', 'mac_address'], 'club_computers_club_mac_index');
            $table->dropUnique('club_computers_club_mac_unique');
        });
    }
};
