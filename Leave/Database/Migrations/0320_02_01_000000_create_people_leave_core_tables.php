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
        Schema::create('people_leave_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->boolean('paid')->default(true);
            $table->string('default_unit')->default('day');
            $table->unsignedSmallInteger('hour_quantum_minutes')->nullable();
            $table->unsignedTinyInteger('default_approval_depth')->default(1);
            $table->boolean('interacts_with_payroll')->default(false);
            $table->boolean('compulsory_attachment')->default(false);
            $table->string('payroll_pay_item_code')->nullable();
            $table->string('audit_tag')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('active')->index();
            $table->string('pack_identifier')->nullable();
            $table->string('pack_version')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });
        $this->registerTable('people_leave_types');

        Schema::create('people_leave_entitlement_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('people_leave_types')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('accrual_method');
            $table->unsignedTinyInteger('earned_until_month')->nullable();
            $table->string('entitlement_rounding')->default('none');
            $table->boolean('prorate_for_joiners')->default(true);
            $table->boolean('prorate_for_leavers')->default(true);
            $table->decimal('bring_forward_cap_days', 8, 4)->nullable();
            $table->unsignedTinyInteger('bring_forward_expiry_month')->nullable();
            $table->string('bring_forward_anchor')->nullable();
            $table->json('eligibility_predicate')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('statutory_floor_pack_identifier')->nullable();
            $table->string('statutory_floor_pack_version')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'leave_type_id', 'effective_from'], 'people_leave_entitlement_policies_company_type_from_index');
        });
        $this->registerTable('people_leave_entitlement_policies');

        Schema::create('people_leave_entitlement_policy_bands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_entitlement_policy_id');
            $table->decimal('min_years_of_service', 6, 2)->default(0);
            $table->decimal('max_years_of_service', 6, 2)->nullable();
            $table->decimal('entitlement_days', 8, 4);
            $table->decimal('bring_forward_cap_days_override', 8, 4)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('leave_entitlement_policy_id', 'people_leave_policy_band_policy_id_fk')
                ->references('id')
                ->on('people_leave_entitlement_policies')
                ->cascadeOnDelete();
            $table->unique(
                ['leave_entitlement_policy_id', 'min_years_of_service'],
                'people_leave_policy_band_policy_min_years_unique'
            );
        });
        $this->registerTable('people_leave_entitlement_policy_bands');

        Schema::create('people_leave_request_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->nullable()->constrained('people_leave_types')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->boolean('allow_negative_balance')->default(false);
            $table->boolean('include_pending_as_taken')->default(true);
            $table->boolean('allow_multiple_applications_per_day')->default(false);
            $table->boolean('no_cross_month_split')->default(false);
            $table->boolean('compulsory_attachment')->default(false);
            $table->boolean('exclude_holiday_from_count')->default(true);
            $table->boolean('exclude_off_day_from_count')->default(true);
            $table->boolean('exclude_rest_day_from_count')->default(true);
            $table->json('day_of_week_unit_overrides')->nullable();
            $table->decimal('max_days_per_application', 8, 4)->nullable();
            $table->json('advance_notice')->nullable();
            $table->json('back_date')->nullable();
            $table->json('replacement_expiry')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'leave_type_id', 'effective_from'], 'people_leave_request_policies_company_type_from_index');
        });
        $this->registerTable('people_leave_request_policies');

        Schema::create('people_leave_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->foreignId('leave_type_id')->constrained('people_leave_types')->cascadeOnDelete();
            $table->foreignId('leave_entitlement_policy_id')
                ->constrained('people_leave_entitlement_policies')
                ->cascadeOnDelete();
            $table->foreignId('leave_request_policy_id')
                ->constrained('people_leave_request_policies')
                ->cascadeOnDelete();
            $table->json('cohort_predicate')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'leave_type_id', 'effective_from'], 'people_leave_assignments_company_type_from_index');
        });
        $this->registerTable('people_leave_assignments');

        Schema::create('people_leave_balance_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('leave_type_id')->constrained('people_leave_types');
            $table->unsignedSmallInteger('leave_year');
            $table->string('entry_type');
            $table->decimal('quantity', 10, 4);
            $table->string('unit')->default('day');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('entitlement_policy_id')->nullable();
            $table->unsignedInteger('entitlement_policy_version')->nullable();
            $table->unsignedBigInteger('request_policy_id')->nullable();
            $table->unsignedInteger('request_policy_version')->nullable();
            $table->string('pack_identifier')->nullable();
            $table->string('pack_version')->nullable();
            $table->date('occurred_on');
            $table->date('expires_on')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['employee_id', 'leave_type_id', 'leave_year'], 'people_leave_ledger_entries_employee_type_year_index');
            $table->index(['company_id', 'occurred_on'], 'people_leave_ledger_entries_company_date_index');
            $table->index(['source_type', 'source_id']);
        });
        $this->registerTable('people_leave_balance_ledger_entries');

        Schema::create('people_leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('leave_type_id')->constrained('people_leave_types');
            $table->foreignId('leave_assignment_id')->nullable()->constrained('people_leave_assignments')->nullOnDelete();
            $table->unsignedBigInteger('leave_request_policy_id')->nullable();
            $table->unsignedInteger('leave_request_policy_version')->nullable();
            $table->string('status')->default('draft')->index();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('unit')->default('day');
            $table->decimal('quantity', 8, 4)->default(0);
            $table->unsignedSmallInteger('attachment_count')->default(0);
            $table->foreignId('on_behalf_actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('on_behalf_reason')->nullable();
            $table->boolean('short_notice')->default(false);
            $table->boolean('back_dated')->default(false);
            $table->string('emergency_tag')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('approval_workflow_ref')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('applied_ledger_entry_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'starts_on', 'ends_on'], 'people_leave_requests_company_employee_dates_index');
            $table->index(['company_id', 'leave_type_id', 'status']);
        });
        $this->registerTable('people_leave_requests');

        Schema::create('people_leave_request_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('people_leave_requests')->cascadeOnDelete();
            $table->date('occurs_on');
            $table->string('portion')->default('full');
            $table->decimal('hours_count', 5, 2)->nullable();
            $table->string('daytype')->default('working');
            $table->boolean('counts_against_balance')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['leave_request_id', 'occurs_on', 'portion'], 'people_leave_request_days_request_date_portion_unique');
            $table->index(['occurs_on']);
        });
        $this->registerTable('people_leave_request_days');

        Schema::create('people_leave_request_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('people_leave_requests')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['leave_request_id', 'occurred_at'], 'people_leave_audit_events_request_occurred_index');
        });
        $this->registerTable('people_leave_request_audit_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('people_leave_request_audit_events');
        Schema::dropIfExists('people_leave_request_days');
        Schema::dropIfExists('people_leave_requests');
        Schema::dropIfExists('people_leave_balance_ledger_entries');
        Schema::dropIfExists('people_leave_assignments');
        Schema::dropIfExists('people_leave_request_policies');
        Schema::dropIfExists('people_leave_entitlement_policy_bands');
        Schema::dropIfExists('people_leave_entitlement_policies');
        Schema::dropIfExists('people_leave_types');
    }
};
