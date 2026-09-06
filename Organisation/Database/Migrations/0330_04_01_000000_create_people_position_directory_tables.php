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
    private array $tables = ['people_position_versions', 'people_position_assignments'];

    public function up(): void
    {
        Schema::create('people_position_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('company_entity_id');
            $table->string('position_stable_id');
            $table->unsignedInteger('version');
            $table->string('title');
            // The interval belongs to the position. A vacancy does not end it,
            // which is what lets a vacant position keep its job description.
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'ppv_owner_uq');
            $table->unique(['tenant_id', 'company_entity_id', 'position_stable_id', 'version'], 'ppv_version_uq');
            $table->index(['tenant_id', 'company_entity_id', 'position_stable_id', 'effective_from'], 'ppv_effective_idx');
            $table->foreign(['company_entity_id', 'tenant_id'], 'ppv_company_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
        });

        Schema::create('people_position_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('company_entity_id');
            $table->string('position_stable_id');
            $table->unsignedBigInteger('employee_entity_id');
            $table->string('type', 16);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'ppa_owner_uq');
            $table->index(['tenant_id', 'company_entity_id', 'position_stable_id', 'effective_from'], 'ppa_position_idx');
            $table->index(['tenant_id', 'company_entity_id', 'employee_entity_id', 'effective_from'], 'ppa_employee_idx');
            $table->foreign(['company_entity_id', 'tenant_id'], 'ppa_company_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
            $table->foreign('employee_entity_id', 'ppa_employee_fk')
                ->references('id')->on('employees')->restrictOnDelete();
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
};
