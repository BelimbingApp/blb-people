<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
    public function up(): void
    {
        if (Schema::hasColumn('people_leave_types', 'payroll_pay_item_code')) {
            Schema::table('people_leave_types', function (Blueprint $table): void {
                $table->dropColumn('payroll_pay_item_code');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('people_leave_types', 'payroll_pay_item_code')) {
            Schema::table('people_leave_types', function (Blueprint $table): void {
                $table->string('payroll_pay_item_code')->nullable()->after('compulsory_attachment');
            });
        }
    }
};
