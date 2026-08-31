<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual attendance — a day that the gate missed, entered by hand.
 *
 * Every manual entry is recorded here, including the ones a company enters
 * itself and that apply immediately. That costs one row and buys a single
 * register of every hand-entered day: who asked, who agreed, and why. An
 * attendance record that can be edited without a trail is not evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_attendance_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();

            $t->date('work_date');
            $t->dateTime('in_at');
            $t->dateTime('out_at')->nullable();      // still inside, or a half record
            $t->string('location_name', 100)->nullable();
            $t->text('reason');                      // never optional: this is the evidence

            $t->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $t->text('decision_note')->nullable();

            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();

            // Set on approval so the entry can be traced to, and undone with,
            // the exact logs it produced.
            $t->foreignId('in_log_id')->nullable()->constrained('attendance_logs')->nullOnDelete();
            $t->foreignId('out_log_id')->nullable()->constrained('attendance_logs')->nullOnDelete();

            $t->timestamps();

            $t->index(['company_id', 'status']);
            $t->index(['vendor_id', 'status']);
            $t->index(['worker_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_attendance_requests');
    }
};
