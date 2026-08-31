<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily-wage first.
 *
 * This system is for contract and daily-wage labour, where a worker is hired
 * at a RATE PER DAY, not on a monthly salary. Monthly stays supported because
 * supervisors and staff on the same muster are often paid that way, but daily
 * is the default and the rate is entered directly rather than derived by
 * dividing a monthly figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->enum('wage_type', ['daily', 'monthly'])->default('daily')->after('monthly_rate');
            $table->decimal('daily_rate', 10, 2)->nullable()->after('wage_type');
        });

        // Existing rows were entered as monthly figures — keep them that way.
        \DB::table('workers')->whereNotNull('monthly_rate')->update(['wage_type' => 'monthly']);
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn(['wage_type', 'daily_rate']);
        });
    }
};
