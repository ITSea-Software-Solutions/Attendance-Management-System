<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backup finger: each worker may enroll a SECOND fingerprint (e.g. the other
 * thumb). Attendance verifies against whichever enrolled finger matches —
 * cuts, bandages and worn prints stop blocking the gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->text('fingerprint_template_2')->nullable()->after('fingerprint_quality');
            $table->unsignedTinyInteger('fingerprint_quality_2')->nullable()->after('fingerprint_template_2');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn(['fingerprint_template_2', 'fingerprint_quality_2']);
        });
    }
};
