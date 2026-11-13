<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Resellers bring clubs: a club enters a reseller's code in its settings, pays the
        // subscription with the reseller's discount, and the reseller earns a percent of each payment.
        Schema::create('resellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            // Set by the superadmin; a new reseller starts with none.
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('clubs', function (Blueprint $table) {
            $table->foreignId('reseller_id')->nullable()->after('address')->constrained()->nullOnDelete();
            $table->timestamp('reseller_attached_at')->nullable()->after('reseller_id');
        });

        Schema::table('subscription_invoices', function (Blueprint $table) {
            // The reseller and their percent when the bill was opened; the earning when it was paid.
            $table->foreignId('reseller_id')->nullable()->after('amount')->constrained()->nullOnDelete();
            $table->decimal('reseller_percent', 5, 2)->nullable()->after('reseller_id');
            $table->decimal('reseller_amount', 12, 2)->nullable()->after('reseller_percent');
            $table->index(['reseller_id', 'status', 'paid_at']);
        });

        Schema::create('reseller_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['reseller_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_payouts');
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropIndex(['reseller_id', 'status', 'paid_at']);
            $table->dropConstrainedForeignId('reseller_id');
            $table->dropColumn(['reseller_percent', 'reseller_amount']);
        });
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reseller_id');
            $table->dropColumn('reseller_attached_at');
        });
        Schema::dropIfExists('resellers');
    }
};
