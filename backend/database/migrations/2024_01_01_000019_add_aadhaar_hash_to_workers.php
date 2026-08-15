<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aadhaar dedup: store an HMAC-SHA256 (keyed by APP_KEY) of the full 12-digit
 * Aadhaar number. The full number itself is never persisted — only this hash
 * (for duplicate detection across vendors) and the masked display value.
 * Unique index allows multiple NULLs (legacy workers registered before this).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            if (! Schema::hasColumn('workers', 'aadhaar_hash')) {
                $table->string('aadhaar_hash', 64)->nullable()
                      ->after('aadhaar_number_masked')
                      ->unique('workers_aadhaar_hash_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            if (Schema::hasColumn('workers', 'aadhaar_hash')) {
                $table->dropUnique('workers_aadhaar_hash_unique');
                $table->dropColumn('aadhaar_hash');
            }
        });
    }
};
