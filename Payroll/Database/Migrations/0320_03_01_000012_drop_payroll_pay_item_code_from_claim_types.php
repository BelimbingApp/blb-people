<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 17 — drop the legacy `payroll_pay_item_code` column from
 * `people_claim_types`. The Payroll-side mapping table created in
 * `0320_03_01_000011_*` is now the source of truth.
 *
 * Also drops the `(company_id, payroll_pay_item_code)` index that the
 * column carried.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('people_claim_types', 'payroll_pay_item_code')) {
            Schema::table('people_claim_types', function (Blueprint $table): void {
                $table->dropIndex(['company_id', 'payroll_pay_item_code']);
                $table->dropColumn('payroll_pay_item_code');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('people_claim_types', 'payroll_pay_item_code')) {
            Schema::table('people_claim_types', function (Blueprint $table): void {
                $table->string('payroll_pay_item_code')->nullable()->after('payroll_eligible');
                $table->index(['company_id', 'payroll_pay_item_code']);
            });
        }
    }
};
