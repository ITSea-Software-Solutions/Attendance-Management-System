<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employment and statutory details a contract worker needs before wages can
 * be run: skill grade (the classification minimum wages are set against),
 * PF/ESI identifiers, bank details for transfer, and the salary structure
 * broken into the heads used on an Indian manufacturing wage sheet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            // Employment
            $table->string('designation', 80)->nullable()->after('joining_date');
            $table->string('department', 80)->nullable()->after('designation');
            $table->enum('skill_category', ['unskilled', 'semi_skilled', 'skilled', 'highly_skilled'])
                  ->nullable()->after('department');

            // Statutory identifiers
            $table->string('uan', 12)->nullable()->after('skill_category');           // EPFO universal account no.
            $table->string('pf_number', 30)->nullable()->after('uan');
            $table->string('esic_number', 20)->nullable()->after('pf_number');
            $table->boolean('pf_applicable')->default(true)->after('esic_number');
            $table->boolean('esi_applicable')->default(true)->after('pf_applicable');

            // Payment
            $table->string('bank_account_number', 24)->nullable()->after('esi_applicable');
            $table->string('bank_ifsc', 11)->nullable()->after('bank_account_number');
            $table->string('bank_name', 80)->nullable()->after('bank_ifsc');

            // Salary structure: { basic: 9000, da: 3000, hra: 450, ... }
            $table->json('wage_components')->nullable()->after('ot_multiplier');

            $table->index('uan');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropIndex(['uan']);
            $table->dropColumn([
                'designation', 'department', 'skill_category', 'uan', 'pf_number',
                'esic_number', 'pf_applicable', 'esi_applicable',
                'bank_account_number', 'bank_ifsc', 'bank_name', 'wage_components',
            ]);
        });
    }
};
