<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a training request is actually for (0010-f).
 *
 * A request names a requestor and a department; until now it named nobody who
 * would attend. Subjects are resolved when the request is drafted and frozen
 * with `workforce_observed_at`, so a cohort is the roster as it stood when
 * somebody asked, not as it stands when an event is finally scheduled months
 * later.
 *
 * Declares IncubatingSchema like the request tables it points at
 * (0330_03_05): a stable table holding foreign keys into an incubating one
 * would block the rebuild those tables are still expecting.
 */
return new class extends Migration
{
    use IncubatingSchema, RegistersTables;

    private string $table = 'people_training_request_subjects';

    public function up(): void
    {
        Schema::create($this->table, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('training_request_id');
            $table->string('provider_id', 80);
            $table->string('employee_subject_id', 160);
            $table->timestamp('workforce_observed_at');
            // How this subject came to be on the request: named one by one, or
            // resolved from a department roster. The distinction survives the
            // resolution because a cohort of three and three individuals are
            // the same rows and not the same request.
            $table->string('source', 16);
            $table->string('cohort_reference', 160)->nullable();
            $table->timestamps();

            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'ptrs_subject_owner_uq');
            $table->unique(
                ['training_request_id', 'provider_id', 'employee_subject_id', 'tenant_id'],
                'ptrs_subject_request_uq',
            );
            $table->index(['tenant_id', 'company_entity_id', 'training_request_id'], 'ptrs_subject_request_idx');
            $table->foreign(['training_request_id', 'tenant_id', 'company_entity_id'], 'ptrs_subject_request_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_training_requests')->restrictOnDelete();
            $table->foreign(['company_entity_id', 'tenant_id'], 'ptrs_subject_company_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
        });

        $this->registerTable($this->table);
    }

    public function down(): void
    {
        $this->unregisterTable($this->table);
        Schema::dropIfExists($this->table);
    }
};
