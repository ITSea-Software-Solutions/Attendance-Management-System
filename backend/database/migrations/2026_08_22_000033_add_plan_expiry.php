<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Licence validity: paid plans carry an expiry. NULL = no expiry (trial, or
 * grandfathered orgs). On expiry the org silently degrades to TRIAL limits
 * (computed at read time — no data is touched) until a renewal payment is
 * verified. plan_upgrade_requests.months records what period was bought.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['companies', 'vendors'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->timestamp('plan_expires_at')->nullable()->after('plan_started_at');
            });
        }
        Schema::table('plan_upgrade_requests', function (Blueprint $table) {
            $table->unsignedSmallInteger('months')->default(1)->after('requested_plan');
        });
    }

    public function down(): void
    {
        foreach (['companies', 'vendors'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn('plan_expires_at');
            });
        }
        Schema::table('plan_upgrade_requests', function (Blueprint $table) {
            $table->dropColumn('months');
        });
    }
};
