<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-tap host approval.
 *
 * Hosts are plant staff, not system users — they will not log in to approve a
 * visitor. The pass carries a long random token; the WhatsApp or SMS message
 * links to a public page where the host sees the guest and taps Approve or
 * Deny. The token is the only credential, so it is long, single-purpose, and
 * dies with the pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_passes', function (Blueprint $table) {
            $table->string('approval_token', 64)->nullable()->unique()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('gate_passes', function (Blueprint $table) {
            $table->dropUnique(['approval_token']);
            $table->dropColumn('approval_token');
        });
    }
};
