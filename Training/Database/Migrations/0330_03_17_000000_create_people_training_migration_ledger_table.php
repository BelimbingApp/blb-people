<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration ledger (blb-people#400 / [0015-d]).
 *
 * One row per source workbook/portal row that was either applied or
 * quarantined. Unique on (tenant, company, source_key, sha256, row) so a
 * resumed run cannot double-record the same provenance.
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    /** @var list<string> */
    private array $tables = [
        'people_training_migration_ledger',
    ];

    public function up(): void
    {
        Schema::create('people_training_migration_ledger', function (Blueprint $table): void {
            $this->identity($table, 'ptml');
            $table->string('source_key', 64);
            $table->string('source_sha256', 64);
            $table->unsignedInteger('source_row');
            $table->string('target_table', 128);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('status', 16);
            $table->text('reason')->nullable();
            $table->json('payload_excerpt')->nullable();
            $table->unsignedBigInteger('recorded_by');
            $table->timestamp('recorded_at');
            $table->timestamp('reconciled_at')->nullable();

            $table->unique(
                ['tenant_id', 'company_entity_id', 'source_key', 'source_sha256', 'source_row'],
                'ptml_source_row_uq',
            );
            $table->index(['tenant_id', 'company_entity_id', 'status'], 'ptml_status_idx');
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
