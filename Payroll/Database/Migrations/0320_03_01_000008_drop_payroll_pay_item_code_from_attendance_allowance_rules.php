<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
    public function up(): void
    {
        if (Schema::hasColumn('people_attendance_allowance_rules', 'payroll_pay_item_code')) {
            Schema::table('people_attendance_allowance_rules', function (Blueprint $table): void {
                $table->dropColumn('payroll_pay_item_code');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('people_attendance_allowance_rules', 'payroll_pay_item_code')) {
            Schema::table('people_attendance_allowance_rules', function (Blueprint $table): void {
                $table->string('payroll_pay_item_code')->nullable()->after('allowance_type');
            });
        }
    }
};
