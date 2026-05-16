<?php

use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
    use RegistersTables;

    public function up(): void
    {
        Schema::create('people_payroll_claim_type_pay_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('claim_type_id')->constrained('people_claim_types')->cascadeOnDelete();
            $table->string('payroll_pay_item_code');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['claim_type_id', 'effective_from'],
                'people_payroll_claim_type_pay_items_type_effective_unique',
            );
            $table->index(['company_id', 'claim_type_id']);
        });
        $this->registerTable('people_payroll_claim_type_pay_items');

        if (Schema::hasColumn('people_claim_types', 'payroll_pay_item_code')) {
            $now = now();
            $types = DB::table('people_claim_types')
                ->whereNotNull('payroll_pay_item_code')
                ->where('payroll_pay_item_code', '!=', '')
                ->get(['id', 'company_id', 'payroll_pay_item_code']);

            foreach ($types as $type) {
                DB::table('people_payroll_claim_type_pay_items')->insert([
                    'company_id' => $type->company_id,
                    'claim_type_id' => $type->id,
                    'payroll_pay_item_code' => $type->payroll_pay_item_code,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'metadata' => json_encode(['migrated_from' => 'claim_type.payroll_pay_item_code']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->unregisterTable('people_payroll_claim_type_pay_items');
        Schema::dropIfExists('people_payroll_claim_type_pay_items');
    }
};
