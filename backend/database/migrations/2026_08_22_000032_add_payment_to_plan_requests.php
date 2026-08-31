<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline payment, formalised: the org records HOW it paid (method +
 * reference/UTR + amount + proof upload) on its upgrade request; the super
 * admin sees the proof and verifies before activating the plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_upgrade_requests', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable()->after('note');   // upi | bank_transfer | cash | cheque
            $table->string('payment_reference', 80)->nullable()->after('payment_method'); // UTR / txn id / cheque no
            $table->decimal('amount', 10, 2)->nullable()->after('payment_reference');
            $table->string('payment_proof_path')->nullable()->after('amount'); // private disk
            $table->timestamp('paid_at')->nullable()->after('payment_proof_path');
        });
    }

    public function down(): void
    {
        Schema::table('plan_upgrade_requests', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'payment_reference', 'amount', 'payment_proof_path', 'paid_at']);
        });
    }
};
