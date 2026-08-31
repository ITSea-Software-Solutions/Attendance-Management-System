<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wage changes proposed by a contractor, approved by the company.
 *
 * Both sides can set a rate, but the company is the one paying: a contractor
 * raising their own workers' wages must have it agreed before it reaches the
 * register. The previously agreed rate stays in force until then, so nothing
 * changes under the payer's feet.
 *
 * A worker sitting on the bench has no company paying for them, so the
 * contractor sets those rates freely — there is nobody to approve it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wage_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();

            // What is being asked for.
            $table->enum('wage_type', ['daily', 'monthly'])->default('daily');
            $table->decimal('daily_rate', 10, 2)->nullable();
            $table->decimal('monthly_rate', 10, 2)->nullable();
            $table->json('wage_components')->nullable();

            // What it is now, captured at request time so the company can see
            // the change even if the live record moves on.
            $table->enum('current_wage_type', ['daily', 'monthly'])->nullable();
            $table->decimal('current_daily_rate', 10, 2)->nullable();
            $table->decimal('current_monthly_rate', 10, 2)->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('note', 255)->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['worker_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wage_change_requests');
    }
};
