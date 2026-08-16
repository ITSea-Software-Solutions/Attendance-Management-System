<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generic biometrics:
 *  - workers.face_descriptor (512-D ArcFace embedding, JSON) + face_enrolled_at
 *    — camera-based identification, hardware-free.
 *  - attendance_logs.method widened to every supported verification method.
 *    (The old enum lacked 'photo'/'id_card' even though the API accepted them —
 *    inserts would have failed. 'face' = camera match; 'device_auth' = the
 *    worker's own registered device confirming via its OS biometric.)
 *  - attendance_logs.face_score — cosine similarity (0–1) established
 *    server-side at mark time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            if (! Schema::hasColumn('workers', 'face_descriptor')) {
                $table->json('face_descriptor')->nullable()->after('fingerprint_quality');
                $table->timestamp('face_enrolled_at')->nullable()->after('face_descriptor');
            }
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('attendance_logs', 'face_score')) {
                $table->decimal('face_score', 4, 3)->nullable()->after('fingerprint_score');
            }
        });

        DB::statement("ALTER TABLE attendance_logs MODIFY COLUMN method
            ENUM('fingerprint','face','photo','id_card','device_auth','manual')
            NOT NULL DEFAULT 'fingerprint'");
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            if (Schema::hasColumn('workers', 'face_descriptor')) {
                $table->dropColumn(['face_descriptor', 'face_enrolled_at']);
            }
        });
        Schema::table('attendance_logs', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_logs', 'face_score')) {
                $table->dropColumn('face_score');
            }
        });
        DB::statement("ALTER TABLE attendance_logs MODIFY COLUMN method
            ENUM('fingerprint','manual') NOT NULL DEFAULT 'fingerprint'");
    }
};
