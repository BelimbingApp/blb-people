<?php

use App\Modules\People\Payroll\Database\Support\PayrollPayItemMigrationSupport;
use Illuminate\Database\Migrations\Migration;

/**
 * Plan 12 Phase 2 — pay-item code is now owned by Payroll via the
 * people_payroll_attendance_rule_pay_items mapping table. Drop the
 * legacy column on the attendance allowance rule row.
 *
 * Companion migration `0320_03_01_000007_*` copied any existing values
 * into the mapping table first, so this drop is non-destructive for
 * existing data. Lives in the Payroll migrations directory (not the
 * Attendance one) because it completes a Payroll-driven cleanup and
 * must run after `_000007_*`.
 */
return new class extends Migration
{
    use PayrollPayItemMigrationSupport;

    public function up(): void
    {
        $this->dropLegacyPayItemCodeColumn('people_attendance_allowance_rules');
    }

    public function down(): void
    {
        $this->restoreLegacyPayItemCodeColumn('people_attendance_allowance_rules', 'allowance_type');
    }
};
