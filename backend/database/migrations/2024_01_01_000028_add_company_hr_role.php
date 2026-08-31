<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New role: company_hr — department HR who reviews vendor deployments
 * (approve/reject, assign allowed departments) and views workers/attendance.
 * users.location_name doubles as the HR user's own department label.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role
            ENUM('super_admin','company_admin','company_hr','company_gate','vendor_admin','vendor_operator')
            NOT NULL DEFAULT 'company_gate'");
    }

    public function down(): void
    {
        DB::statement("UPDATE users SET role = 'company_admin' WHERE role = 'company_hr'");
        DB::statement("ALTER TABLE users MODIFY COLUMN role
            ENUM('super_admin','company_admin','company_gate','vendor_admin','vendor_operator')
            NOT NULL DEFAULT 'company_gate'");
    }
};
