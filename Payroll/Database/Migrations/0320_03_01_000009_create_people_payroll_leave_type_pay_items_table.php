<?php

use App\Base\Database\Concerns\RegistersTables;
use App\Modules\People\Payroll\Database\Support\PayrollPayItemMigrationSupport;
use Illuminate\Database\Migrations\Migration;

/**
 * Plan 16 — move pay-item-code assignment from `people_leave_types`
 * to a Payroll-owned mapping table.
 *
 * Mirror of `0320_03_01_000007_create_people_payroll_attendance_rule_pay_items_table.php`.
 * Copies non-null values from `LeaveType.payroll_pay_item_code` into
 * mapping rows. A companion migration drops the column.
 */
return new class extends Migration
{
    use PayrollPayItemMigrationSupport;
    use RegistersTables;

    public function up(): void
    {
        $this->createPayrollPayItemMappingTable(
            'people_payroll_leave_type_pay_items',
            'leave_type_id',
            'people_leave_types',
            null,
            'people_payroll_leave_type_pay_items_type_effective_unique',
            'people_payroll_leave_items_company_type_index',
            companyNullable: true,
        );

        $this->copyLegacyPayItemCodes(
            'people_leave_types',
            'people_payroll_leave_type_pay_items',
            'leave_type_id',
            'leave_type.payroll_pay_item_code',
        );
    }

    public function down(): void
    {
        $this->unregisterTable('people_payroll_leave_type_pay_items');
        Schema::dropIfExists('people_payroll_leave_type_pay_items');
    }
};
