<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gate passes gain a vehicle: most plant visitors arrive in one, and the
 * number plate is what security actually writes in the register. A second
 * photo covers the vehicle; at least one of the two photos is required so a
 * pass can never be raised with no visual record at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_passes', function (Blueprint $table) {
            $table->string('vehicle_number', 20)->nullable()->after('purpose');
            $table->string('vehicle_photo_path', 255)->nullable()->after('photo_path');
            $table->index('vehicle_number');
        });
    }

    public function down(): void
    {
        Schema::table('gate_passes', function (Blueprint $table) {
            $table->dropIndex(['vehicle_number']);
            $table->dropColumn(['vehicle_number', 'vehicle_photo_path']);
        });
    }
};
