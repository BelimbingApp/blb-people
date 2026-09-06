<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    /** @var list<string> */
    private array $tables = [
        'people_training_department_budgets',
        'people_training_department_budget_audits',
    ];

    public function up(): void
    {
        Schema::create('people_training_department_budgets', function (Blueprint $table): void {
            $this->identity($table, 'ptdb');
            $table->unsignedBigInteger('department_entity_id');
            $table->unsignedSmallInteger('budget_year');
            // decimal(19,4) is Payroll's shape for an amount; the roll-up adds
            // these with bcadd rather than trusting float arithmetic.
            $table->decimal('amount', 19, 4);
            $table->unsignedBigInteger('set_by_user_id');

            $table->unique(['tenant_id', 'company_entity_id', 'department_entity_id', 'budget_year'], 'ptdb_year_uq');
        });

        Schema::create('people_training_department_budget_audits', function (Blueprint $table): void {
            $this->identity($table, 'ptdba');
            $table->unsignedBigInteger('training_department_budget_id');
            // Null on the first allocation: there was no amount before it.
            $table->decimal('previous_amount', 19, 4)->nullable();
            $table->decimal('amount', 19, 4);
            $table->text('reason');
            $table->unsignedBigInteger('actor_user_id');
            $table->timestamp('occurred_at');

            $table->index(['tenant_id', 'company_entity_id', 'training_department_budget_id'], 'ptdba_budget_idx');
            $table->foreign(['training_department_budget_id', 'tenant_id', 'company_entity_id'], 'ptdba_budget_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_training_department_budgets')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        foreach ($this->tables as $table) {
            $this->registerTable($table);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            $this->unregisterTable($table);
            Schema::dropIfExists($table);
        }
    }

    private function identity(Blueprint $table, string $prefix): void
    {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->index();
        $table->unsignedBigInteger('company_entity_id');
        $table->timestamps();
        $table->unique(['id', 'tenant_id', 'company_entity_id'], $prefix.'_owner_uq');
        $table->foreign(['company_entity_id', 'tenant_id'], $prefix.'_company_fk')
            ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
    }
};
