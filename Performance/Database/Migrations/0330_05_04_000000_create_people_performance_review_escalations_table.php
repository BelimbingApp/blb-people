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
    private array $tables = ['people_performance_review_escalations'];

    public function up(): void
    {
        Schema::create('people_performance_review_escalations', function (Blueprint $table): void {
            $this->identity($table, 'ppresc');
            $table->unsignedBigInteger('review_id');
            // The manager who was reminded and did not act.
            $table->unsignedBigInteger('manager_user_id');
            // Their own manager. Null when the reporting line ends here, in
            // which case the audience carries who reads it instead.
            $table->unsignedBigInteger('escalated_to_user_id')->nullable();
            $table->string('audience', 16);
            $table->string('fortnight_key', 8);
            $table->timestamp('notified_at');

            $table->unique(
                ['tenant_id', 'company_entity_id', 'review_id', 'fortnight_key'],
                'ppresc_fortnight_uq',
            );
            $table->index(['tenant_id', 'company_entity_id', 'notified_at'], 'ppresc_recent_idx');
            $table->foreign(['review_id', 'tenant_id', 'company_entity_id'], 'ppresc_review_fk')
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
