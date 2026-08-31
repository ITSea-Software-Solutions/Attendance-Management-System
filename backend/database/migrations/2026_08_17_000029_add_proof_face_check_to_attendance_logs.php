<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic cross-check of the gate proof photo against the worker's
 * enrolled face — an independent second factor on fingerprint/manual marks.
 * Filled asynchronously by the VerifyProofPhoto job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->decimal('proof_face_score', 4, 3)->nullable()->after('face_score');
            $table->boolean('proof_face_match')->nullable()->after('proof_face_score');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn(['proof_face_score', 'proof_face_match']);
        });
    }
};
