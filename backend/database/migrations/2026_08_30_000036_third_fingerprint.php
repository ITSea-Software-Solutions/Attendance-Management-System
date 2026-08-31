<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third finger. Sites with heavy manual labour wear prints down; two fingers
 * still leaves a worker stranded when one is cut and the other is worn.
 * Any of the three enrolled fingers verifies at the gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->text('fingerprint_template_3')->nullable()->after('fingerprint_quality_2');
            $table->unsignedTinyInteger('fingerprint_quality_3')->nullable()->after('fingerprint_template_3');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn(['fingerprint_template_3', 'fingerprint_quality_3']);
        });
    }
};
