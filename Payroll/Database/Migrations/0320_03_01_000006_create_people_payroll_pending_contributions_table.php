<?php

use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use RegistersTables;

    public function up(): void
    {
        Schema::create('people_payroll_pending_contributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('pay_item_code');
            $table->date('period_anchor');
            $table->date('occurred_on');
            $table->string('input_type');
            $table->string('currency', 3);
            $table->decimal('amount', 12, 4)->nullable();
            $table->decimal('quantity', 12, 4)->nullable();
            $table->decimal('rate', 12, 4)->nullable();
            $table->string('label');
            $table->json('accounting_snapshot')->nullable();
            $table->string('state')->default('pending')->index();
            $table->foreignId('payroll_input_id')->nullable()->constrained('people_payroll_inputs')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('materialized_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_type', 'source_id', 'pay_item_code', 'period_anchor'],
                'people_payroll_pending_contributions_source_unique',
            );
            $table->index(['company_id', 'state', 'period_anchor']);
            $table->index(['employee_id', 'occurred_on']);
        });
        $this->registerTable('people_payroll_pending_contributions');
    }

    public function down(): void
    {
        $this->unregisterTable('people_payroll_pending_contributions');
        Schema::dropIfExists('people_payroll_pending_contributions');
    }
};
