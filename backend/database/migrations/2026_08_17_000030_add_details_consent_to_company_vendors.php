<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor consent for the company's vendor-detail view: when a vendor requests
 * access they agree the company may view their organisation profile and track
 * the working history (workers, deployments, attendance). Company-created
 * vendors consent implicitly at creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_vendors', function (Blueprint $table) {
            $table->timestamp('details_consent_at')->nullable()->after('status');
        });

        // Existing approved relationships predate the consent checkbox —
        // grandfather them (dev-mode data; production starts consented).
        DB::table('company_vendors')->where('status', 'approved')
            ->update(['details_consent_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('company_vendors', function (Blueprint $table) {
            $table->dropColumn('details_consent_at');
        });
    }
};
