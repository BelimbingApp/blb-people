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
        Schema::create('payroll_statutory_rule_sets', function (Blueprint $table): void {
            $table->id();
            $table->char('country_iso', 2);
            $table->string('rule_key');
            $table->string('name');
            $table->string('source_pack');
            $table->string('source_version');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('rounding_policy')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique([
                'country_iso',
                'rule_key',
                'source_pack',
                'source_version',
                'effective_from',
            ], 'payroll_statutory_rule_sets_unique_effective_key');
            $table->index([
                'country_iso',
                'rule_key',
                'effective_from',
                'effective_to',
            ], 'payroll_statutory_rule_sets_effective_index');
        });
        $this->registerTable('payroll_statutory_rule_sets');

        Schema::create('payroll_statutory_rule_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_statutory_rule_set_id')->constrained('payroll_statutory_rule_sets')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('row_key')->nullable();
            $table->decimal('min_wage', 19, 4)->nullable();
            $table->decimal('max_wage', 19, 4)->nullable();
            $table->decimal('employee_rate', 12, 8)->nullable();
            $table->decimal('employer_rate', 12, 8)->nullable();
            $table->decimal('employee_amount', 19, 4)->nullable();
            $table->decimal('employer_amount', 19, 4)->nullable();
            $table->decimal('levy_rate', 12, 8)->nullable();
            $table->json('row_data')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['payroll_statutory_rule_set_id', 'sort_order'], 'payroll_statutory_rule_rows_set_order_index');
            $table->index(['payroll_statutory_rule_set_id', 'min_wage', 'max_wage'], 'payroll_statutory_rule_rows_wage_band_index');
        });
        $this->registerTable('payroll_statutory_rule_rows');
    }

    public function down(): void
    {
        $this->unregisterTable('payroll_statutory_rule_rows');
        $this->unregisterTable('payroll_statutory_rule_sets');

        Schema::dropIfExists('payroll_statutory_rule_rows');
        Schema::dropIfExists('payroll_statutory_rule_sets');
    }
};
