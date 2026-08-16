<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DPDP compliance: registration requires the registering organisation to
 * confirm the worker's informed consent for identity + biometric processing.
 * The confirmation timestamp is stored here (referenced by the Privacy Policy).
 * Nullable: workers registered before this feature are grandfathered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            if (! Schema::hasColumn('workers', 'consent_confirmed_at')) {
                $table->timestamp('consent_confirmed_at')->nullable()->after('aadhaar_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            if (Schema::hasColumn('workers', 'consent_confirmed_at')) {
                $table->dropColumn('consent_confirmed_at');
            }
        });
    }
};
