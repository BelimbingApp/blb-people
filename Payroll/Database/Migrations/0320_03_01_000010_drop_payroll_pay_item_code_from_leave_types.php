<?php

use App\Modules\People\Payroll\Database\Support\PayrollPayItemMigrationSupport;
use Illuminate\Database\Migrations\Migration;

/**
 * Plan 16 — drop the legacy `payroll_pay_item_code` column from
 * `people_leave_types`. The Payroll-side mapping table created in
 * `0320_03_01_000009_*` is now the source of truth.
 *
 * Lives in the Payroll migrations directory (not Leave's) because it
 * completes a Payroll-driven cleanup and must run after the data-copy
 * migration `_000009_*`.
 */
return new class extends Migration
{
    use PayrollPayItemMigrationSupport;

    public function up(): void
    {
        $this->dropLegacyPayItemCodeColumn('people_leave_types');
    }

    public function down(): void
    {
        $this->restoreLegacyPayItemCodeColumn('people_leave_types', 'compulsory_attachment');
    }
};
