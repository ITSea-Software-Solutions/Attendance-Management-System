<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline-first client app sync: records created on devices carry a
 * client-generated UUID so pushes are idempotent (retries never duplicate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            if (! Schema::hasColumn('workers', 'client_uuid')) {
                $table->char('client_uuid', 36)->nullable()->after('id')
                      ->unique('workers_client_uuid_unique');
            }
        });
        Schema::table('attendance_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('attendance_logs', 'client_uuid')) {
                $table->char('client_uuid', 36)->nullable()->after('id')
                      ->unique('attendance_client_uuid_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            if (Schema::hasColumn('workers', 'client_uuid')) {
                $table->dropUnique('workers_client_uuid_unique');
                $table->dropColumn('client_uuid');
            }
        });
        Schema::table('attendance_logs', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_logs', 'client_uuid')) {
                $table->dropUnique('attendance_client_uuid_unique');
                $table->dropColumn('client_uuid');
            }
        });
    }
};
