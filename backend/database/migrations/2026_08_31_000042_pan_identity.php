<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAN as an alternative identity document.
 *
 * Not every worker turns up with an Aadhaar in hand. A PAN card identifies
 * the person well enough to register them and start attendance; the Aadhaar
 * can follow. pan_hash mirrors aadhaar_hash so the same person cannot be
 * registered twice across contractors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->string('pan_hash', 64)->nullable()->after('pan_number');
            $table->string('pan_card_path', 255)->nullable()->after('pan_hash');
            $table->timestamp('pan_verified_at')->nullable()->after('pan_card_path');
            $table->index('pan_hash');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropIndex(['pan_hash']);
            $table->dropColumn(['pan_hash', 'pan_card_path', 'pan_verified_at']);
        });
    }
};
