<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visitor / Gate-Pass module:
 *  - company_hosts: the HR-maintained list of people who may receive visitors
 *  - gate_passes:   one row per visitor pass created at a gate
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('phone', 15);
            $table->string('position', 80)->nullable();
            $table->string('department', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('gate_passes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();          // GP-20260819-0001
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('host_id')->constrained('company_hosts')->cascadeOnDelete();
            $table->string('guest_name', 120);
            $table->string('guest_phone', 15)->nullable();
            $table->string('purpose', 200)->nullable();
            $table->string('photo_path')->nullable();       // private disk, live gate photo
            // pending → approved / denied / expired; approval via WhatsApp
            // reply or a gate/HR manual override (audited, with note)
            $table->string('status', 20)->default('pending');
            $table->string('decided_via', 20)->nullable();  // whatsapp | manual
            $table->string('decision_note', 200)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('entry_at')->nullable();
            $table->timestamp('exit_at')->nullable();
            $table->string('location_name', 100)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_passes');
        Schema::dropIfExists('company_hosts');
    }
};
