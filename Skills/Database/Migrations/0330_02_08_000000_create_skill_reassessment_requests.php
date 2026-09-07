<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reassessment requests for the HOD reassessment loop (0006-b).
 *
 * One open request per employee and skill is an application rule, not a
 * unique key: a resolved or cancelled request must not block the next one,
 * and a partial unique index would behave differently per driver. The store
 * refuses the duplicate inside a transaction instead.
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    /** @var list<string> */
    private array $tables = [
        'people_connector_skill_reassessment_requests',
    ];

    public function up(): void
    {
        Schema::create('people_connector_skill_reassessment_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->timestamps();
            $table->unsignedBigInteger('employee_entity_id');
            $table->unsignedBigInteger('skill_id');
            $table->string('reason', 1000);
            $table->unsignedBigInteger('requested_by_user_id');
            $table->date('due_at');
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('performed_by_user_id')->nullable();
            $table->timestamp('performed_at')->nullable();
            $table->string('outcome', 1000)->nullable();
            $table->unique(['id', 'tenant_id'], 'pcr_req_id_tenant_uq');
            $table->index(['tenant_id', 'company_entity_id', 'status', 'due_at'], 'pcr_req_ops_idx');
            $table->index(['tenant_id', 'employee_entity_id', 'skill_id'], 'pcr_req_employee_skill_idx');
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
