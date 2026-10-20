<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How often the PC was offered the agent update: a diskless PC loses the update on restart,
     * so it is offered only a few times per version.
     */
    public function up(): void
    {
        Schema::table('club_computers', function (Blueprint $table) {
            $table->string('agent_update_version', 20)->nullable()->after('agent_version');
            $table->unsignedTinyInteger('agent_update_offers')->default(0)->after('agent_update_version');
            $table->timestamp('agent_update_offered_at')->nullable()->after('agent_update_offers');
        });
    }

    public function down(): void
    {
        Schema::table('club_computers', function (Blueprint $table) {
            $table->dropColumn(['agent_update_version', 'agent_update_offers', 'agent_update_offered_at']);
        });
    }
};
