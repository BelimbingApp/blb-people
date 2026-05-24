<?php

use App\Base\Database\Concerns\RegistersTables;
use App\Modules\People\Payroll\Database\Support\PayrollPayItemMigrationSupport;
use Illuminate\Database\Migrations\Migration;

/**
 * Plan 12 Phase 2 — move pay-item-code assignment from the Attendance
 * allowance-rule table to a Payroll-owned mapping table.
 *
 * The column on people_attendance_allowance_rules.payroll_pay_item_code
 * stays in place for now; this migration copies non-null values into the
 * mapping table so the listener can read them via the new path. A later
 * Phase 2 migration drops the column.
 */
return new class extends Migration
{
    use PayrollPayItemMigrationSupport;
    use RegistersTables;

    public function up(): void
    {
        $this->createPayrollPayItemMappingTable(
            'people_payroll_attendance_rule_pay_items',
            'attendance_allowance_rule_id',
            'people_attendance_allowance_rules',
            'people_payroll_att_rule_items_rule_fk',
            'people_payroll_attendance_rule_pay_items_rule_effective_unique',
            'people_payroll_att_rule_items_company_rule_index',
        );

        $this->copyLegacyPayItemCodes(
            'people_attendance_allowance_rules',
            'people_payroll_attendance_rule_pay_items',
            'attendance_allowance_rule_id',
            'attendance_allowance_rule.payroll_pay_item_code',
            'effective_from',
        );
    }

    public function down(): void
    {
        $this->unregisterTable('people_payroll_attendance_rule_pay_items');
        Schema::dropIfExists('people_payroll_attendance_rule_pay_items');
    }
};
