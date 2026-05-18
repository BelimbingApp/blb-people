<?php

use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
    use RegistersTables;

    public function up(): void
    {
        Schema::create('people_payroll_attendance_rule_pay_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('attendance_allowance_rule_id')->constrained('people_attendance_allowance_rules', indexName: 'people_payroll_att_rule_items_rule_fk')->cascadeOnDelete();
            $table->string('payroll_pay_item_code');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['attendance_allowance_rule_id', 'effective_from'],
                'people_payroll_attendance_rule_pay_items_rule_effective_unique',
            );
            $table->index(['company_id', 'attendance_allowance_rule_id'], 'people_payroll_att_rule_items_company_rule_index');
        });
        $this->registerTable('people_payroll_attendance_rule_pay_items');

        if (Schema::hasColumn('people_attendance_allowance_rules', 'payroll_pay_item_code')) {
            $now = now();
            $rules = DB::table('people_attendance_allowance_rules')
                ->whereNotNull('payroll_pay_item_code')
                ->where('payroll_pay_item_code', '!=', '')
                ->get(['id', 'company_id', 'payroll_pay_item_code', 'effective_from']);

            foreach ($rules as $rule) {
                DB::table('people_payroll_attendance_rule_pay_items')->insert([
                    'company_id' => $rule->company_id,
                    'attendance_allowance_rule_id' => $rule->id,
                    'payroll_pay_item_code' => $rule->payroll_pay_item_code,
                    'effective_from' => $rule->effective_from,
                    'effective_to' => null,
                    'metadata' => json_encode(['migrated_from' => 'attendance_allowance_rule.payroll_pay_item_code']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->unregisterTable('people_payroll_attendance_rule_pay_items');
        Schema::dropIfExists('people_payroll_attendance_rule_pay_items');
    }
};
