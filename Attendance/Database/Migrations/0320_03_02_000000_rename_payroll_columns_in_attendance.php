<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 12 Phase 4 — strip remaining payroll-flavored names from
 * Attendance tables where the underlying concept is not actually a
 * payroll concept.
 *
 * Lives in the 0320_03_02 band so it runs after the Payroll-driven
 * pay-item migrations (0320_03_01_*) but before any future Report
 * migrations (0320_03_03_*).
 *
 * Changes:
 *   - rename people_attendance_shift_templates.payroll_attribution
 *     to cross_midnight_attribution (the actual concept).
 *   - replace people_attendance_policy_groups.payroll_defaults JSON
 *     with a plain currency string column (the only field ever
 *     populated in production); OT pay-item resolution moves to its
 *     hardcoded default ('ATT_OT') until a Payroll-side OT mapping
 *     table is built.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('people_attendance_shift_templates', 'payroll_attribution')
            && ! Schema::hasColumn('people_attendance_shift_templates', 'cross_midnight_attribution')) {
            Schema::table('people_attendance_shift_templates', function (Blueprint $table): void {
                $table->renameColumn('payroll_attribution', 'cross_midnight_attribution');
            });
        }

        if (Schema::hasColumn('people_attendance_policy_groups', 'payroll_defaults')) {
            if (! Schema::hasColumn('people_attendance_policy_groups', 'currency')) {
                Schema::table('people_attendance_policy_groups', function (Blueprint $table): void {
                    $table->string('currency', 3)->nullable()->after('name');
                });
            }

            DB::table('people_attendance_policy_groups')
                ->whereNotNull('payroll_defaults')
                ->orderBy('id')
                ->each(function ($row): void {
                    $defaults = is_string($row->payroll_defaults) ? json_decode($row->payroll_defaults, true) : $row->payroll_defaults;
                    $currency = is_array($defaults) ? ($defaults['currency'] ?? null) : null;
                    if (is_string($currency) && $currency !== '') {
                        DB::table('people_attendance_policy_groups')
                            ->where('id', $row->id)
                            ->update(['currency' => strtoupper($currency)]);
                    }
                });

            Schema::table('people_attendance_policy_groups', function (Blueprint $table): void {
                $table->dropColumn('payroll_defaults');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('people_attendance_shift_templates', 'cross_midnight_attribution')
            && ! Schema::hasColumn('people_attendance_shift_templates', 'payroll_attribution')) {
            Schema::table('people_attendance_shift_templates', function (Blueprint $table): void {
                $table->renameColumn('cross_midnight_attribution', 'payroll_attribution');
            });
        }

        if (! Schema::hasColumn('people_attendance_policy_groups', 'payroll_defaults')) {
            Schema::table('people_attendance_policy_groups', function (Blueprint $table): void {
                $table->json('payroll_defaults')->nullable()->after('name');
            });

            DB::table('people_attendance_policy_groups')
                ->whereNotNull('currency')
                ->update(['payroll_defaults' => DB::raw("json_object('currency', currency)")]);
        }

        if (Schema::hasColumn('people_attendance_policy_groups', 'currency')) {
            Schema::table('people_attendance_policy_groups', function (Blueprint $table): void {
                $table->dropColumn('currency');
            });
        }
    }
};
