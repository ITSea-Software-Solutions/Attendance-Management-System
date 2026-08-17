<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The photo EXTRACTED from the worker's Aadhaar PDF, persisted so gate
 * devices can show it beside the live photos (the PDF itself is password-
 * protected, so re-extraction on demand isn't possible server-side).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            if (! Schema::hasColumn('workers', 'aadhaar_photo_path')) {
                $table->string('aadhaar_photo_path')->nullable()->after('aadhaar_pdf_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn('aadhaar_photo_path');
        });
    }
};
