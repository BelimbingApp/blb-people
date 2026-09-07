<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skill reminder delivery ledger (0009-g).
 *
 * One row per reminder, recipient and ISO week. The unique key, not the
 * application, is what makes a retry or an overlapping run a no-op instead of
 * a second message: the row is written before anything is sent, so a second
 * writer loses the race at the database and never reaches notify().
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    /** @var list<string> */
    private array $tables = [
        'people_connector_skill_reminder_deliveries',
    ];

    public function up(): void
    {
        Schema::create('people_connector_skill_reminder_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->timestamps();
            $table->string('rule', 40);
            $table->unsignedBigInteger('employee_entity_id');
            $table->unsignedBigInteger('skill_id');
            // 0 for a score reminder: a NULL in a unique key compares equal to
            // nothing on every driver, so it would let two rows through.
            $table->unsignedBigInteger('development_action_id')->default(0);
            $table->date('due_on');
            // ISO year-week, e.g. 2026-W37: the weekly cadence is a property
            // of the row, not of how often the scheduler happens to run.
            $table->string('period_key', 8);
            $table->unsignedBigInteger('recipient_user_id');
            $table->string('state', 12);
            $table->string('failure', 2000)->nullable();
            $table->timestamp('attempted_at');
            $table->timestamp('sent_at')->nullable();
            $table->unique(['id', 'tenant_id'], 'pcr_deliv_id_tenant_uq');
            $table->unique(
                ['tenant_id', 'company_entity_id', 'rule', 'employee_entity_id', 'skill_id', 'development_action_id', 'period_key', 'recipient_user_id'],
                'pcr_deliv_period_uq',
            );
            $table->index(['tenant_id', 'company_entity_id', 'state', 'period_key'], 'pcr_deliv_state_idx');
            $table->foreign(['company_entity_id', 'tenant_id'], 'pcr_deliv_company_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
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
