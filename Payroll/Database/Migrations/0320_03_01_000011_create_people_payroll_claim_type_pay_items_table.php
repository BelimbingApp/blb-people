<?php

use App\Base\Database\Concerns\RegistersTables;
use App\Modules\People\Payroll\Database\Support\PayrollPayItemMigrationSupport;
use Illuminate\Database\Migrations\Migration;

/**
 * Plan 17 — move pay-item-code assignment from `people_claim_types`
 * to a Payroll-owned mapping table.
 *
 * Mirror of plan 12 / plan 16. Copies non-null values from
 * `ClaimType.payroll_pay_item_code` non-destructively. A companion
 * migration drops the column.
 */
return new class extends Migration
{
    use PayrollPayItemMigrationSupport;
    use RegistersTables;

    public function up(): void
    {
        $this->createPayrollPayItemMappingTable(
            'people_payroll_claim_type_pay_items',
            'claim_type_id',
            'people_claim_types',
            null,
            'people_payroll_claim_type_pay_items_type_effective_unique',
            'people_payroll_claim_items_company_type_index',
            companyNullable: true,
        );

        $this->copyLegacyPayItemCodes(
            'people_claim_types',
            'people_payroll_claim_type_pay_items',
            'claim_type_id',
            'claim_type.payroll_pay_item_code',
        );
    }

    public function down(): void
    {
        $this->unregisterTable('people_payroll_claim_type_pay_items');
        Schema::dropIfExists('people_payroll_claim_type_pay_items');
    }
};
