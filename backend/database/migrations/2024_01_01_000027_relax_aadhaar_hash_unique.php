<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aadhaar dedup becomes a CONFIG-ENFORCED rule (biometric.aadhaar_dedup,
 * default ON) instead of a hard DB unique index, so demo/test environments
 * can register the same Aadhaar on multiple workers (AADHAAR_DEDUP=false).
 * A plain index remains for the lookup speed of the code-level check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropUnique('workers_aadhaar_hash_unique');
            $table->index('aadhaar_hash', 'workers_aadhaar_hash_index');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropIndex('workers_aadhaar_hash_index');
            $table->unique('aadhaar_hash', 'workers_aadhaar_hash_unique');
        });
    }
};
