<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk-import onboarding: PAN + joining date on workers, and an EXPLICIT
 * Aadhaar-verified flag. Workers may now be imported WITHOUT Aadhaar
 * (verified later, behind the scenes); the flag + filter track the backlog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->string('emp_code', 30)->nullable()->after('name');       // client's own employee code
            $table->string('pan_number', 10)->nullable()->after('aadhaar_number_masked');
            $table->date('joining_date')->nullable()->after('dob');
            $table->timestamp('aadhaar_verified_at')->nullable()->after('aadhaar_hash');
            // same code may exist at different vendors; unique within one
            $table->unique(['vendor_id', 'emp_code']);
        });

        // Backfill: every worker that already carries an Aadhaar (hash from the
        // PDF-extract or manual 12-digit path) counts as verified as of its
        // registration date.
        DB::table('workers')->whereNotNull('aadhaar_hash')
            ->update(['aadhaar_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropUnique(['vendor_id', 'emp_code']);
            $table->dropColumn(['emp_code', 'pan_number', 'joining_date', 'aadhaar_verified_at']);
        });
    }
};
