<?php

use App\Modules\People\Payroll\Database\Support\PayrollPayItemMigrationSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

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
    use PayrollPayItemMigrationSupport;

    public function up(): void
    {
        $this->dropLegacyPayItemCodeColumn(
            'people_claim_types',
            fn (Blueprint $table): mixed => $table->dropIndex(['company_id', 'payroll_pay_item_code']),
        );
    }

    public function down(): void
    {
        $this->restoreLegacyPayItemCodeColumn(
            'people_claim_types',
            'payroll_eligible',
            fn (Blueprint $table): mixed => $table->index(['company_id', 'payroll_pay_item_code']),
        );
    }
};
