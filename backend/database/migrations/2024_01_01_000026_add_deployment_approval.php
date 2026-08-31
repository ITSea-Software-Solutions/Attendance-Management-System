<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deployment approval + per-gate access control:
 *  - companies may require HR approval of vendor deployments
 *    (companies.settings.require_deployment_approval)
 *  - approval can restrict the worker to specific gates/departments
 *    (allowed_locations JSON; NULL = every gate of the company)
 * Existing rows default to 'approved' with no restriction — behaviour is
 * unchanged until a company opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('worker_assignments', 'approval_status')) {
                $table->string('approval_status', 20)->default('approved')->after('status');
                $table->unsignedBigInteger('approved_by')->nullable()->after('approval_status');
                $table->timestamp('approved_at')->nullable()->after('approved_by');
                $table->string('rejection_reason', 300)->nullable()->after('approved_at');
                $table->json('allowed_locations')->nullable()->after('rejection_reason');
                $table->index(['company_id', 'approval_status']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'approved_by', 'approved_at', 'rejection_reason', 'allowed_locations']);
        });
    }
};
