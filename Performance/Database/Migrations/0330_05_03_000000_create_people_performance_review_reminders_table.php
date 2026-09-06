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
    private array $tables = ['people_performance_review_reminders'];

    public function up(): void
    {
        Schema::create('people_performance_review_reminders', function (Blueprint $table): void {
            $this->identity($table, 'pprem');
            $table->unsignedBigInteger('review_id');
            $table->unsignedBigInteger('manager_user_id');
            $table->string('reason', 24);
            // The ISO year-week this reminder belongs to. The weekly cadence is
            // a property of the row, not of how often the job happens to run:
            // the unique key below is what makes a retry, or an operator
            // running the command by hand, a no-op.
            $table->string('week_key', 8);
            $table->timestamp('notified_at');

            $table->unique(
                ['tenant_id', 'company_entity_id', 'manager_user_id', 'review_id', 'week_key'],
                'pprem_week_uq',
            );
            $table->index(['tenant_id', 'company_entity_id', 'notified_at'], 'pprem_recent_idx');
            $table->foreign(['review_id', 'tenant_id', 'company_entity_id'], 'pprem_review_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_performance_reviews')
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
