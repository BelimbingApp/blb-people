<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field and code mapping register and authoritative writer windows
 * (blb-people#398 / [0015-b]).
 *
 * Mappings say where each legacy field lands and how overlapping records are
 * deduplicated; writer windows say which system may write a workflow between
 * two dates. The sign-off is one row per company: its unique key is what makes
 * "signed" a fact, and the store appends nothing to either register once the
 * row exists.
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    /** @var list<string> */
    private array $tables = [
        'people_training_migration_field_mappings',
        'people_training_migration_writer_windows',
        'people_training_migration_mapping_signoffs',
    ];

    public function up(): void
    {
        Schema::create('people_training_migration_field_mappings', function (Blueprint $table): void {
            $this->identity($table, 'ptmfm');
            $table->unsignedBigInteger('training_migration_source_id');
            $table->string('source_field', 160);
            $table->string('source_code', 160)->nullable();
            $table->string('target_table', 64);
            $table->string('target_column', 64);
            $table->text('dedup_rule');
            $table->unsignedBigInteger('created_by_user_id');

            $table->foreign(['training_migration_source_id', 'tenant_id', 'company_entity_id'], 'ptmfm_source_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_training_migration_sources')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('people_training_migration_writer_windows', function (Blueprint $table): void {
            $this->identity($table, 'ptmww');
            $table->string('workflow', 32);
            $table->string('writer', 16);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedBigInteger('created_by_user_id');

            $table->index(['tenant_id', 'company_entity_id', 'workflow'], 'ptmww_workflow_ix');
        });

        Schema::create('people_training_migration_mapping_signoffs', function (Blueprint $table): void {
            $this->identity($table, 'ptmms');
            $table->unsignedBigInteger('signed_by_user_id');
            $table->timestamp('signed_at');
            $table->text('note')->nullable();

            $table->unique(['tenant_id', 'company_entity_id'], 'ptmms_company_uq');
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
