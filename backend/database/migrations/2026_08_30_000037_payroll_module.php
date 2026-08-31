<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll / wage register.
 *
 * Modelled on how contract-labour wages are actually run in Indian plants:
 * a monthly rate ("stipend") divided by a fixed divisor (usually 26) to get a
 * day rate, paid per PRESENT day, plus overtime at day-rate / shift-hours.
 * Rates live per worker because every band differs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->decimal('monthly_rate', 10, 2)->nullable()->after('joining_date');
            $table->unsignedTinyInteger('wage_divisor')->nullable()->after('monthly_rate'); // days, default 26
            $table->unsignedTinyInteger('ot_divisor')->nullable()->after('wage_divisor');   // shift hours, default 8
            $table->decimal('ot_multiplier', 4, 2)->nullable()->after('ot_divisor');        // 1.0 = single rate
        });

        // Public / festival holidays per company — a holiday is not an absence.
        Schema::create('company_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('holiday_date');
            $table->string('name', 120);
            $table->boolean('paid')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'holiday_date']);
        });

        // Arrears, advances, deductions and bonuses applied to one pay period.
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('type', ['arrear', 'advance', 'deduction', 'bonus']);
            $table->decimal('amount', 10, 2);           // stored positive; type decides the sign
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'period_start', 'period_end']);
        });

        // Manual overtime / day-status overrides, with an approver on record.
        Schema::create('attendance_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->decimal('ot_hours', 5, 2)->nullable();         // overrides computed OT
            $table->enum('status', ['P', 'A', 'WO', 'H'])->nullable(); // overrides derived status
            $table->string('reason', 255)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['worker_id', 'company_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_overrides');
        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('company_holidays');
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn(['monthly_rate', 'wage_divisor', 'ot_divisor', 'ot_multiplier']);
        });
    }
};
