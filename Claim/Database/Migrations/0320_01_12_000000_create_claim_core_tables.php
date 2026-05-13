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
        Schema::create('claim_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active')->index();
            $table->string('source_system')->nullable()->index();
            $table->string('source_label')->nullable();
            $table->string('source_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });
        $this->registerTable('claim_categories');

        Schema::create('claim_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('claim_category_id')->nullable()->constrained('claim_categories')->nullOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('default_unit')->default('amount');
            $table->string('calculation_mode')->default('manual_amount');
            $table->string('receipt_requirement')->default('always');
            $table->boolean('provider_required')->default(false);
            $table->boolean('payroll_eligible')->default(true);
            $table->string('payroll_pay_item_code')->nullable();
            $table->string('debit_account_code')->nullable();
            $table->string('credit_account_code')->nullable();
            $table->string('taxability_hint')->nullable();
            $table->string('benefit_kind')->nullable();
            $table->string('approval_route_key')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('allow_employee_submission')->default(true);
            $table->boolean('allow_on_behalf_submission')->default(true);
            $table->boolean('admin_only')->default(false);
            $table->boolean('advance_settlement_allowed')->default(false);
            $table->string('status')->default('active')->index();
            $table->string('source_system')->nullable()->index();
            $table->string('source_label')->nullable();
            $table->string('source_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'claim_category_id', 'status']);
            $table->index(['company_id', 'payroll_pay_item_code']);
        });
        $this->registerTable('claim_types');

        Schema::create('claim_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('item_mode')->default('single_value');
            $table->boolean('auto_calculated')->default(false);
            $table->string('rate_type')->nullable();
            $table->json('cohort_predicate')->nullable();
            $table->json('receipt_rules')->nullable();
            $table->json('provider_rules')->nullable();
            $table->json('currency_rules')->nullable();
            $table->json('advance_rules')->nullable();
            $table->string('approval_profile_key')->nullable();
            $table->boolean('encumber_pending')->default(true);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('active')->index();
            $table->string('source_system')->nullable()->index();
            $table->string('source_label')->nullable();
            $table->string('source_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'item_mode', 'effective_from']);
        });
        $this->registerTable('claim_policies');

        Schema::create('claim_policy_bands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('claim_policy_id')->constrained('claim_policies')->cascadeOnDelete();
            $table->string('logical_operator')->default('<=');
            $table->decimal('threshold_value', 12, 4)->nullable();
            $table->decimal('rate', 12, 4)->default(0);
            $table->decimal('per_day_unit_limit', 12, 2)->nullable();
            $table->decimal('per_claim_limit', 12, 2)->nullable();
            $table->decimal('per_month_limit', 12, 2)->nullable();
            $table->decimal('per_year_limit', 12, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['claim_policy_id', 'sort_order']);
        });
        $this->registerTable('claim_policy_bands');

        Schema::create('claim_contexts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('label');
            $table->decimal('max_claim_limit', 12, 2)->nullable();
            $table->string('status')->default('active')->index();
            $table->string('source_system')->nullable()->index();
            $table->string('source_label')->nullable();
            $table->string('source_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });
        $this->registerTable('claim_contexts');

        Schema::create('claim_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->json('cohort_predicate')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status')->default('active')->index();
            $table->string('source_system')->nullable()->index();
            $table->string('source_label')->nullable();
            $table->string('source_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status', 'effective_from']);
        });
        $this->registerTable('claim_assignments');

        Schema::create('claim_assignment_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('claim_assignment_id')->constrained('claim_assignments')->cascadeOnDelete();
            $table->foreignId('claim_type_id')->constrained('claim_types')->cascadeOnDelete();
            $table->foreignId('claim_policy_id')->constrained('claim_policies')->cascadeOnDelete();
            $table->string('combine_tag')->nullable();
            $table->boolean('uses_combined_cap')->default(false);
            $table->boolean('hidden_from_application')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['claim_assignment_id', 'claim_type_id']);
            $table->index(['claim_assignment_id', 'status', 'sort_order']);
        });
        $this->registerTable('claim_assignment_lines');

        Schema::create('claim_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('claim_assignment_id')->nullable()->constrained('claim_assignments')->nullOnDelete();
            $table->foreignId('claim_context_id')->nullable()->constrained('claim_contexts')->nullOnDelete();
            $table->string('reference_number')->nullable()->index();
            $table->string('status')->default('draft')->index();
            $table->string('currency', 3)->default('MYR');
            $table->decimal('requested_amount', 12, 2)->default(0);
            $table->decimal('approved_amount', 12, 2)->default(0);
            $table->decimal('reimbursed_amount', 12, 2)->default(0);
            $table->string('approval_profile_key')->nullable();
            $table->json('approval_route_snapshot')->nullable();
            $table->json('strictest_line_snapshot')->nullable();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('on_behalf_actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('on_behalf_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('queued_for_payroll_at')->nullable();
            $table->timestamp('reimbursed_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'status']);
            $table->index(['company_id', 'status', 'submitted_at']);
        });
        $this->registerTable('claim_requests');

        Schema::create('claim_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('claim_request_id')->constrained('claim_requests')->cascadeOnDelete();
            $table->foreignId('claim_type_id')->constrained('claim_types');
            $table->foreignId('claim_policy_id')->nullable()->constrained('claim_policies')->nullOnDelete();
            $table->foreignId('claim_assignment_line_id')->nullable()->constrained('claim_assignment_lines')->nullOnDelete();
            $table->date('incurred_on');
            $table->string('description')->nullable();
            $table->string('unit')->default('amount');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('rate', 12, 4)->nullable();
            $table->decimal('requested_amount', 12, 2);
            $table->decimal('approved_amount', 12, 2)->default(0);
            $table->decimal('reimbursed_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('MYR');
            $table->string('provider_name')->nullable();
            $table->string('receipt_number')->nullable();
            $table->unsignedSmallInteger('attachment_count')->default(0);
            $table->string('payroll_pay_item_code')->nullable();
            $table->string('debit_account_code')->nullable();
            $table->string('credit_account_code')->nullable();
            $table->string('adjustment_reason')->nullable();
            $table->json('policy_snapshot')->nullable();
            $table->json('accounting_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['claim_request_id', 'incurred_on']);
            $table->index(['claim_type_id', 'incurred_on']);
            $table->index(['receipt_number']);
        });
        $this->registerTable('claim_lines');

        Schema::create('claim_entitlement_usage_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('claim_type_id')->constrained('claim_types');
            $table->foreignId('claim_policy_id')->nullable()->constrained('claim_policies')->nullOnDelete();
            $table->foreignId('claim_line_id')->nullable()->constrained('claim_lines')->nullOnDelete();
            $table->unsignedSmallInteger('claim_year');
            $table->string('entry_type');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('MYR');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->date('occurred_on');
            $table->string('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'claim_type_id', 'claim_year']);
            $table->index(['company_id', 'occurred_on']);
            $table->index(['source_type', 'source_id']);
        });
        $this->registerTable('claim_entitlement_usage_entries');

        Schema::create('claim_request_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('claim_request_id')->constrained('claim_requests')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['claim_request_id', 'occurred_at']);
        });
        $this->registerTable('claim_request_audit_events');
    }

    public function down(): void
    {
        foreach ([
            'claim_request_audit_events',
            'claim_entitlement_usage_entries',
            'claim_lines',
            'claim_requests',
            'claim_assignment_lines',
            'claim_assignments',
            'claim_contexts',
            'claim_policy_bands',
            'claim_policies',
            'claim_types',
            'claim_categories',
        ] as $table) {
            $this->unregisterTable($table);
            Schema::dropIfExists($table);
        }
    }
};
