<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS plans: every org (company OR vendor) carries a plan. Self-served
 * signups start on 'trial'; upgrades are requested in-app and approved by
 * the super admin (offline payment for now). Orgs existing before this
 * migration are grandfathered to 'enterprise' so nothing breaks for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['companies', 'vendors'] as $t) {
            Schema::table($t, function (Blueprint $table) use ($t) {
                if (! Schema::hasColumn($t, 'plan')) {
                    $table->string('plan', 20)->default('trial')->after('status');
                    $table->timestamp('plan_started_at')->nullable()->after('plan');
                }
            });
            DB::table($t)->whereNull('plan_started_at')
                ->update(['plan' => 'enterprise', 'plan_started_at' => now()]);
        }

        if (! Schema::hasTable('plan_upgrade_requests')) {
            Schema::create('plan_upgrade_requests', function (Blueprint $table) {
                $table->id();
                $table->string('org_type', 10);            // company | vendor
                $table->unsignedBigInteger('org_id');
                $table->string('current_plan', 20);
                $table->string('requested_plan', 20);
                $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('note')->nullable();
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
                $table->index(['org_type', 'org_id']);
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        foreach (['companies', 'vendors'] as $t) {
            Schema::table($t, function (Blueprint $table) use ($t) {
                if (Schema::hasColumn($t, 'plan')) {
                    $table->dropColumn(['plan', 'plan_started_at']);
                }
            });
        }
        Schema::dropIfExists('plan_upgrade_requests');
    }
};
